<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\CategoryNames;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\DeletePmPlan;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\SchedulePmVisits;
use App\Modules\Maintenance\Actions\SearchPmPlans;
use App\Modules\Maintenance\Http\Requests\PmPlanRequest;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PmPlanController extends Controller
{
    public function __construct(
        private ListCustomers $listCustomers,
        private ContractDetails $contractDetails,
        private UserNames $userNames,
        private UsersWithPermission $usersWithPermission,
    ) {}

    public function index(Request $request, SearchPmPlans $search): Response
    {
        Gate::authorize('viewAny', PmPlan::class);

        $filters = SearchPmPlans::filtersFrom($request);
        $plans = $search->handle($request->user(), $filters)->paginate(20)->withQueryString();
        $customers = collect($this->listCustomers->handle(withTrashed: true))->pluck('name', 'id');
        $contracts = $this->contractDetails->handle($plans->pluck('contract_id')->all());
        $assignees = $this->userNames->handle($plans->pluck('assignee_id')->all());

        return Inertia::render('Maintenance/Plans/Index', [
            'plans' => $plans->through(fn (PmPlan $plan) => [
                ...$plan->only(['id', 'title', 'interval_months', 'visits_count', 'completed_count', 'overdue_count']),
                'customer' => $customers[$plan->customer_id] ?? null,
                'contract_no' => $contracts[$plan->contract_id]['contract_no'] ?? null,
                'assignee' => $assignees[$plan->assignee_id] ?? null,
                'starts_on' => $plan->starts_on->toDateString(),
                'ends_on' => $plan->ends_on->toDateString(),
            ]),
            'filters' => $filters,
            // A customer account does not filter by customer.
            'customers' => $request->user()->customer_id === null ? $this->listCustomers->handle() : [],
            'can' => ['create' => $request->user()->can('create', PmPlan::class)],
        ]);
    }

    /**
     * ?contract_id=... preselects a contract (e.g. from the contract page).
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', PmPlan::class);

        $planned = PmPlan::query()->pluck('contract_id')->all();
        $contracts = collect($this->contractDetails->handle(null, active: true))
            ->reject(fn (array $contract) => in_array($contract['id'], $planned, true))
            ->map(fn (array $contract) => [
                ...collect($contract)->only(['id', 'contract_no', 'title', 'customer', 'starts_on', 'ends_on', 'pm_interval_months'])->all(),
                'assets_count' => count($contract['asset_ids']),
            ])
            ->values();

        $contractId = $request->integer('contract_id') ?: null;

        return Inertia::render('Maintenance/Plans/Form', [
            ...$this->formProps(null),
            'contracts' => $contracts,
            'preselectedContractId' => $contractId,
            // The equipment of the contract picked (reloaded when another is picked: ?contract_id=).
            'contractAssets' => fn () => $contractId ? $this->contractAssets($contractId) : [],
        ]);
    }

    public function store(PmPlanRequest $request, SavePmPlan $savePlan): RedirectResponse
    {
        $plan = $savePlan->handle(null, $request->validated());

        return redirect()->route('maintenance.plans.show', $plan)->with('success', __('maintenance.plans.created', ['count' => $plan->visits()->count()]));
    }

    /**
     * The equipment of a contract to pick from: what the filters of the plan form need.
     *
     * @return list<array<string, mixed>>
     */
    private function contractAssets(int $contractId): array
    {
        $ids = $this->contractDetails->handle([$contractId])[$contractId]['asset_ids'] ?? [];
        $categories = app(CategoryNames::class)->handle(withTrashed: true);

        return collect(app(AssetDetails::class)->handle($ids))
            ->map(fn (array $asset) => [
                ...collect($asset)->only(['id', 'asset_code', 'name', 'brand', 'model', 'serial_number', 'location', 'floor', 'room'])->all(),
                'category' => $categories[$asset['category_id']] ?? null,
            ])
            ->sortBy('asset_code')->values()->all();
    }

    public function show(Request $request, PmPlan $plan): Response
    {
        Gate::authorize('view', $plan);

        $user = $request->user();
        $contract = $this->contractDetails->handle([$plan->contract_id])[$plan->contract_id] ?? null;
        $visits = $plan->visits()->withCount(['items'])->orderBy('round')->get();
        $planAssets = $plan->assets()->pluck('asset_id')->all();
        $names = $this->userNames->handle([$plan->assignee_id, ...$visits->pluck('assignee_id')->all()]);

        return Inertia::render('Maintenance/Plans/Show', [
            'plan' => [
                ...$plan->only(['id', 'title', 'interval_months', 'notes']),
                'customer' => collect($this->listCustomers->handle(withTrashed: true))->firstWhere('id', $plan->customer_id)['name'] ?? null,
                'contract' => $contract ? [
                    ...collect($contract)->only(['id', 'contract_no', 'title'])->all(),
                    'assets_count' => count($contract['asset_ids']),
                    'can_view' => $user->can('contracts.view'),
                ] : null,
                'assignee' => $names[$plan->assignee_id] ?? null,
                'starts_on' => $plan->starts_on->toDateString(),
                'ends_on' => $plan->ends_on->toDateString(),
                'active' => $plan->active,
                // Equipment chosen for the plan; none = every asset of the contract.
                'assets' => $planAssets === [] ? [] : collect(app(AssetDetails::class)->handle($planAssets))
                    ->map(fn (array $asset) => collect($asset)->only(['asset_code', 'name', 'location', 'floor', 'room'])->all())->sortBy('asset_code')->values(),
            ],
            // Rounds still to come, with the day their work orders will be made.
            'upcoming' => app(SchedulePmVisits::class)->upcoming($plan),
            'visits' => $visits->map(fn (PmVisit $visit) => [
                ...$visit->only(['ulid', 'visit_no', 'round', 'status', 'items_count']),
                'period_starts_on' => $visit->period_starts_on->toDateString(),
                'due_on' => $visit->due_on->toDateString(),
                'scheduled_on' => $visit->scheduled_on?->toDateString(),
                'completed_at' => $visit->completed_at?->toIso8601String(),
                'assignee' => $names[$visit->assignee_id] ?? null,
                'overdue' => $visit->isOverdue(),
            ]),
            'can' => [
                'update' => $user->can('update', $plan),
                'delete' => $user->can('delete', $plan),
                // equipment from Excel (PmPlanImportController)
                'import' => $user->can('update', $plan) && $user->can('assets.import'),
            ],
        ]);
    }

    public function edit(PmPlan $plan): Response
    {
        Gate::authorize('update', $plan);

        return Inertia::render('Maintenance/Plans/Form', $this->formProps($plan) + [
            // The interval can only change while no round has started.
            'intervalLocked' => $plan->visits()->where('status', '!=', PmVisit::STATUS_SCHEDULED)->exists(),
        ]);
    }

    public function update(PmPlanRequest $request, PmPlan $plan, SavePmPlan $savePlan): RedirectResponse
    {
        $savePlan->handle($plan, $request->validated());

        return redirect()->route('maintenance.plans.show', $plan)->with('success', __('maintenance.plans.updated'));
    }

    public function destroy(PmPlan $plan, DeletePmPlan $deletePlan): RedirectResponse
    {
        Gate::authorize('delete', $plan);

        $deletePlan->handle($plan);

        return redirect()->route('maintenance.plans.index')->with('success', __('maintenance.plans.deleted'));
    }

    private function formProps(?PmPlan $plan): array
    {
        return [
            'plan' => $plan ? [
                ...$plan->only(['id', 'title', 'interval_months', 'assignee_id', 'notes', 'active', 'contract_id']),
                'starts_on' => $plan->starts_on->toDateString(),
                'ends_on' => $plan->ends_on->toDateString(),
                'asset_ids' => $plan->assets()->pluck('asset_id')->map(fn ($id) => (int) $id)->all(),
                'contract_no' => $this->contractDetails->handle([$plan->contract_id])[$plan->contract_id]['contract_no'] ?? null,
                'contract_starts_on' => $this->contractDetails->handle([$plan->contract_id])[$plan->contract_id]['starts_on'] ?? null,
                'contract_ends_on' => $this->contractDetails->handle([$plan->contract_id])[$plan->contract_id]['ends_on'] ?? null,
            ] : null,
            'contractAssets' => $plan ? $this->contractAssets($plan->contract_id) : [],
            'intervals' => PmPlan::INTERVALS,
            'assignees' => $this->usersWithPermission->handle(PmPlanRequest::ASSIGNABLE_PERMISSION)
                ->map(fn (User $user) => $user->only(['id', 'name']))->values(),
        ];
    }
}
