<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Contract\Actions\DeleteContract;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Contract\Actions\SaveContract;
use App\Modules\Contract\Actions\SearchContracts;
use App\Modules\Contract\Http\Requests\ContractRequest;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractPhase;
use App\Modules\Document\Actions\AddAttachments;
use App\Modules\Document\Support\Attachments;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function index(Request $request, SearchContracts $search, ListCustomers $listCustomers): Response
    {
        Gate::authorize('viewAny', Contract::class);

        $filters = SearchContracts::filtersFrom($request);

        $user = $request->user();
        $contracts = $search->handle($filters, $user)
            ->with('customer:id,code,name')
            ->withCount('contractAssets')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Contract $contract) => [
                'id' => $contract->id,
                'contract_no' => $contract->contract_no,
                'title' => $contract->title,
                'customer' => $contract->customer?->name,
                'starts_on' => $contract->starts_on->toDateString(),
                'ends_on' => $contract->ends_on->toDateString(),
                'value' => Money::toBaht($contract->value),
                'service_window' => $contract->service_window,
                'phase' => ContractPhase::of($contract),
                'assets_count' => $contract->contract_assets_count,
            ]);

        return Inertia::render('Contract/Contracts/Index', [
            'contracts' => $contracts,
            'filters' => $filters,
            // Only the customers whose contracts the user reaches.
            'customers' => $listCustomers->handle(user: $user, permission: 'contracts.view'),
            'phases' => ContractPhase::PHASES,
            'serviceWindows' => Contract::SERVICE_WINDOWS,
            'can' => ['create' => $user->can('create', Contract::class)],
        ]);
    }

    public function create(Request $request, ListCustomers $listCustomers): Response
    {
        Gate::authorize('create', Contract::class);

        return Inertia::render('Contract/Contracts/Form', $this->formProps($request, null, $listCustomers) + [
            'preselectedCustomerId' => $request->integer('customer_id') ?: null,
        ]);
    }

    public function store(ContractRequest $request, SaveContract $saveContract, AddAttachments $addAttachments): RedirectResponse
    {
        $contract = $saveContract->handle(null, $request->contractData());
        $addAttachments->handle($contract, $request->attachments());

        return redirect()->route('contract.contracts.show', $contract)->with('success', __('contract.contracts.created'));
    }

    /**
     * ?asset_search=... fills "candidates": assets of the customer that can still be added.
     */
    public function show(Request $request, Contract $contract, AssetSummaries $assetSummaries, Modules $modules): Response
    {
        Gate::authorize('view', $contract);

        $contract->load(['customer', 'slas']);
        $user = $request->user();
        $assetIds = $contract->contractAssets()->pluck('asset_id')->all();
        $canUpdate = $user->can('update', $contract);
        $assetsOn = $modules->enabled('asset') && $user->can('assets.view');

        return Inertia::render('Contract/Contracts/Show', [
            'contract' => [
                ...$contract->only(['id', 'contract_no', 'title', 'status', 'service_window', 'pm_interval_months', 'notify_days_before', 'notes']),
                'customer' => $contract->customer?->only(['id', 'code', 'name', 'contact_name', 'phone', 'email']),
                'starts_on' => $contract->starts_on->toDateString(),
                'ends_on' => $contract->ends_on->toDateString(),
                'value' => Money::toBaht($contract->value),
                'phase' => ContractPhase::of($contract),
                'slas' => collect(Contract::PRIORITIES)->map(function (string $priority) use ($contract) {
                    $sla = $contract->slas->firstWhere('priority', $priority);

                    return [
                        'priority' => $priority,
                        'response_minutes' => $sla?->response_minutes,
                        'resolve_minutes' => $sla?->resolve_minutes,
                    ];
                }),
            ],
            // Assets the user cannot see (other branch) are counted but not listed.
            'assetCount' => count($assetIds),
            'assets' => $assetsOn ? $assetSummaries->handle($user, ['ids' => $assetIds]) : [],
            'assetSearch' => $request->string('asset_search')->trim()->value(),
            'candidates' => fn () => $assetsOn && $canUpdate && $request->filled('asset_search')
                ? $assetSummaries->handle($user, [
                    'customer_id' => $contract->customer_id,
                    'exclude' => $assetIds,
                    'search' => $request->string('asset_search')->value(),
                    'limit' => 20,
                ])
                : [],
            'documents' => $this->documents($contract),
            'history' => $contract->activities()->latest('id')->limit(20)->get()->map(fn ($log) => [
                'id' => $log->id,
                'description' => $log->description,
                'event' => $log->event,
                'actor' => $log->properties['actor']['name'] ?? null,
                'at' => $log->created_at->toIso8601String(),
            ]),
            'can' => [
                'update' => $canUpdate,
                'delete' => $user->can('delete', $contract),
                'manageAssets' => $canUpdate && $assetsOn,
            ],
        ]);
    }

    public function edit(Request $request, Contract $contract, ListCustomers $listCustomers): Response
    {
        Gate::authorize('update', $contract);

        return Inertia::render('Contract/Contracts/Form', $this->formProps($request, $contract, $listCustomers));
    }

    public function update(ContractRequest $request, Contract $contract, SaveContract $saveContract, AddAttachments $addAttachments): RedirectResponse
    {
        $saveContract->handle($contract, $request->contractData());
        $addAttachments->handle($contract, $request->attachments());

        return redirect()->route('contract.contracts.show', $contract)->with('success', __('contract.contracts.updated'));
    }

    public function destroy(Contract $contract, DeleteContract $deleteContract): RedirectResponse
    {
        Gate::authorize('delete', $contract);

        $deleteContract->handle($contract);

        return redirect()->route('contract.contracts.index')->with('success', __('contract.contracts.deleted'));
    }

    /**
     * @return list<array{id: int, name: string, size: int, uploaded_at: string|null, url: string}>
     */
    private function documents(Contract $contract): array
    {
        return Attachments::list($contract, $contract->attachmentCollection(), fn (int $id) => route('contract.contracts.documents.show', [$contract, $id]));
    }

    private function formProps(Request $request, ?Contract $contract, ListCustomers $listCustomers): array
    {
        // The customers the contract may be for: within reach of creating / changing contracts.
        $customers = $listCustomers->handle(user: $request->user(), permission: $contract ? 'contracts.update' : 'contracts.create');
        if ($contract && ! collect($customers)->contains('id', $contract->customer_id)) {
            $customers = [...$customers, ...collect($listCustomers->handle(true))->where('id', $contract->customer_id)->values()->all()];
        }

        return [
            'documents' => $contract ? $this->documents($contract) : [],
            'contract' => $contract ? [
                ...$contract->only(['id', 'customer_id', 'contract_no', 'title', 'status', 'service_window', 'pm_interval_months', 'notify_days_before', 'notes']),
                'starts_on' => $contract->starts_on->toDateString(),
                'ends_on' => $contract->ends_on->toDateString(),
                'value' => Money::toBaht($contract->value),
                'slas' => $contract->slas->mapWithKeys(fn ($sla) => [$sla->priority => [
                    'response_hours' => $sla->response_minutes / 60,
                    'resolve_hours' => $sla->resolve_minutes / 60,
                ]]),
            ] : null,
            'customers' => $customers,
            'statuses' => Contract::STATUSES,
            'serviceWindows' => Contract::SERVICE_WINDOWS,
            'pmIntervals' => Contract::PM_INTERVALS,
            'priorities' => Contract::PRIORITIES,
        ];
    }
}
