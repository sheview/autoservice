<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\AddPmVisitItems;
use App\Modules\Maintenance\Actions\CreatePmWorkOrder;
use App\Modules\Maintenance\Actions\PmTemplatesForAssets;
use App\Modules\Maintenance\Actions\RemovePmVisitItem;
use App\Modules\Maintenance\Http\Requests\PmPlanRequest;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Work orders made by hand (not cut from a plan), and the equipment on any work order still to do:
 * pm-visits.create to make one, pm-visits.update to change its equipment.
 */
class PmWorkOrderController extends Controller
{
    public function __construct(
        private ListCustomers $listCustomers,
        private ContractDetails $contractDetails,
        private AssetSummaries $assetSummaries,
        private UsersWithPermission $usersWithPermission,
    ) {}

    /**
     * ?customer_id= fills the contracts and the equipment of that customer (the register, Asset), each with
     * the template it would get; ?contract_id= (from the contract page) also picks the contract, and its customer.
     */
    public function create(Request $request, PmTemplatesForAssets $templatesFor): Response
    {
        Gate::authorize('create', PmVisit::class);
        abort_if($request->user()->customer_id !== null, 403);

        $contractId = $request->integer('contract_id') ?: null;
        $contract = $contractId ? ($this->contractDetails->handle([$contractId], active: true)[$contractId] ?? null) : null;
        $customerId = $contract['customer_id'] ?? ($request->integer('customer_id') ?: null);
        $assets = $customerId ? $this->assetSummaries->handle($request->user(), ['customer_id' => $customerId, 'limit' => 500]) : [];

        return Inertia::render('Maintenance/Visits/Create', [
            'customers' => $this->listCustomers->handle(),
            'customerId' => $customerId,
            'contractId' => $contract['id'] ?? null,
            'contracts' => $customerId ? $this->contracts($customerId) : [],
            'assets' => $assets,
            'templates' => $templatesFor->handle(array_column($assets, 'id')),
            'assignees' => $this->usersWithPermission->handle(PmPlanRequest::ASSIGNABLE_PERMISSION)
                ->map(fn (User $user) => $user->only(['id', 'name']))->values(),
        ]);
    }

    public function store(Request $request, CreatePmWorkOrder $create): RedirectResponse
    {
        Gate::authorize('create', PmVisit::class);
        abort_if($request->user()->customer_id !== null, 403);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::in(array_column($this->listCustomers->handle(), 'id'))],
            'contract_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                if (! array_key_exists((int) $value, $this->contracts((int) $request->input('customer_id')))) {
                    $fail(__('maintenance.visits.contract_other_customer'));
                }
            }],
            'title' => ['required', 'string', 'max:255'],
            'due_on' => ['required', 'date'],
            'scheduled_on' => ['nullable', 'date'],
            'assignee_id' => ['nullable', 'integer', Rule::in($this->usersWithPermission->handle(PmPlanRequest::ASSIGNABLE_PERMISSION)->pluck('id')->all())],
            'asset_ids' => ['array', 'max:500'],
            'asset_ids.*' => ['integer', 'distinct'],
            'draft' => ['boolean'],
        ], [], __('maintenance.fields'));

        $visit = $create->handle($data, $request->user());

        return redirect()->route('maintenance.visits.show', $visit)->with('success', __('maintenance.visits.created', ['no' => $visit->visit_no]));
    }

    public function addItems(Request $request, PmVisit $visit, AddPmVisitItems $addItems): RedirectResponse
    {
        Gate::authorize('update', $visit);

        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'max:500'],
            'asset_ids.*' => ['integer', 'distinct'],
        ], [], __('maintenance.fields'));
        // Only equipment the user may see: an asset of another branch is "not found".
        $visible = array_column($this->assetSummaries->handle($request->user(), ['ids' => $data['asset_ids']]), 'id');
        if (count($visible) !== count($data['asset_ids'])) {
            return back()->withErrors(['asset_ids' => __('maintenance.visits.asset_not_found')]);
        }

        $added = $addItems->handle($visit, array_map('intval', $data['asset_ids']), $request->user());

        return back()->with('success', __('maintenance.visits.items_added', ['count' => $added]));
    }

    public function removeItem(Request $request, PmVisit $visit, PmVisitItem $item, RemovePmVisitItem $removeItem): RedirectResponse
    {
        Gate::authorize('update', $visit);

        $removeItem->handle($visit, $item, $request->user());

        return back()->with('success', __('maintenance.visits.item_removed'));
    }

    /**
     * Running contracts of a customer, by id, with the assets they cover.
     *
     * @return array<int, array{id: int, contract_no: string, title: string, asset_ids: list<int>}>
     */
    private function contracts(int $customerId): array
    {
        return collect($this->contractDetails->handle(null, active: true))
            ->where('customer_id', $customerId)
            ->map(fn (array $contract) => collect($contract)->only(['id', 'contract_no', 'title', 'asset_ids'])->all())
            ->all();
    }
}
