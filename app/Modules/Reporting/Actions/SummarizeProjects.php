<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Contract\Actions\SearchContracts;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractPhase;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Reporting\Support\SummaryTotals;
use App\Modules\Service\Actions\TicketCustomerIds;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The summary by project: MA contracts (search, filters and sort of the contract list) with how
 * many issues, loans and purchases were made for each. By default only contracts that have some.
 *
 * Which contracts (summary-projects.view): all / branch = every contract; customer = those of the
 * account's customer, and never purchases or amounts; own = those of the customers of the
 * user's own tickets (TicketCustomerIds); project = those and the projects whose team the user is on.
 */
class SummarizeProjects
{
    public const PERMISSION = 'summary-projects.view';

    public function __construct(
        private SummaryRows $rows,
        private SearchContracts $searchContracts,
        private CustomerLabelNames $customerNames,
        private TicketCustomerIds $ticketCustomerIds,
    ) {}

    /**
     * @return array<string, mixed> SearchContracts::filtersFrom() + items: with | all
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            ...SearchContracts::filtersFrom($request),
            'items' => $request->input('items') === 'all' ? 'all' : 'with',
        ];
    }

    /**
     * Whether the user may see purchases and their amounts in the summaries: not customer accounts.
     */
    public static function showsPurchases(User $viewer): bool
    {
        return $viewer->customer_id === null && DataScope::of($viewer, self::PERMISSION) !== PermissionCatalog::SCOPE_CUSTOMER;
    }

    /**
     * The customers whose contracts the user may see; null = every customer.
     *
     * @return list<int>|null
     */
    public function customerIds(User $viewer): ?array
    {
        return match (DataScope::of($viewer, self::PERMISSION)) {
            PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH => null,
            PermissionCatalog::SCOPE_CUSTOMER => $viewer->customer_id === null ? [] : [(int) $viewer->customer_id],
            PermissionCatalog::SCOPE_OWN => $this->ticketCustomerIds->handle($viewer),
            PermissionCatalog::SCOPE_PROJECT => array_values(array_unique([
                ...$this->ticketCustomerIds->handle($viewer), ...ContractScope::projectCustomerIds(DataScope::projectIds($viewer)),
            ])),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     */
    public function handle(User $viewer, array $filters): LengthAwarePaginator
    {
        $purchases = self::showsPurchases($viewer);
        $rows = fn () => DB::query()->fromSub($this->rows->handle($viewer, $purchases), 'rows');
        $customerIds = $this->customerIds($viewer);

        $contracts = $this->searchContracts->handle($filters)
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds))
            ->when(($filters['items'] ?? 'with') === 'with', fn ($q) => $q->whereIn('id', $rows()->whereNotNull('contract_id')->select('contract_id')))
            ->paginate(20, ['id', 'customer_id', 'contract_no', 'title', 'status', 'starts_on', 'ends_on', 'notify_days_before'])
            ->withQueryString();

        $ids = $contracts->getCollection()->pluck('id')->all();
        $totals = $ids === [] ? collect() : $rows()
            ->selectRaw('contract_id, '.SummaryTotals::SELECT)
            ->whereIn('contract_id', $ids)
            ->groupBy('contract_id')
            ->get()
            ->keyBy('contract_id');
        $customers = $this->customerNames->handle();

        return $contracts->through(fn (Contract $contract) => [
            ...$contract->only(['id', 'contract_no', 'title']),
            'customer' => $customers[$contract->customer_id] ?? null,
            'phase' => ContractPhase::of($contract),
            'starts_on' => $contract->starts_on->toDateString(),
            'ends_on' => $contract->ends_on->toDateString(),
            ...SummaryTotals::of($totals->get($contract->id), $purchases),
        ]);
    }
}
