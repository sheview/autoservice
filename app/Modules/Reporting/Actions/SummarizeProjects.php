<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Contract\Actions\SearchContracts;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractPhase;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Support\SummaryTotals;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The summary by project: MA contracts (search, filters and sort of the contract list) with how
 * many issues, loans and purchases were made for each. By default only contracts that have some.
 */
class SummarizeProjects
{
    public function __construct(
        private SummaryRows $rows,
        private SearchContracts $searchContracts,
        private CustomerLabelNames $customerNames,
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
     * @param  array<string, mixed>  $filters  from filtersFrom()
     */
    public function handle(User $viewer, array $filters): LengthAwarePaginator
    {
        $rows = fn () => DB::query()->fromSub($this->rows->handle($viewer), 'rows');

        $contracts = $this->searchContracts->handle($filters)
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
            ...SummaryTotals::of($totals->get($contract->id)),
        ]);
    }
}
