<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;

/**
 * Period, PM interval and covered assets of contracts, for the Maintenance module.
 * active = only active contracts that have not ended yet (the ones PM can still be planned for).
 */
class ContractDetails
{
    /**
     * @param  list<int>|null  $ids  null = every contract
     * @return array<int, array{id: int, contract_no: string, title: string, status: string, customer_id: int,
     *     customer: string|null, starts_on: string, ends_on: string, pm_interval_months: int|null, asset_ids: list<int>}>
     *                                                                                                                     keyed by contract id
     */
    public function handle(?array $ids, bool $active = false): array
    {
        return Contract::query()
            ->with(['customer:id,name', 'contractAssets:id,contract_id,asset_id'])
            ->when($ids !== null, fn ($q) => $q->whereKey($ids))
            ->when($active, fn ($q) => $q->where('status', Contract::STATUS_ACTIVE)->where('ends_on', '>=', today()->toDateString()))
            ->orderBy('contract_no')
            ->get()
            ->mapWithKeys(fn (Contract $contract) => [$contract->id => [
                'id' => $contract->id,
                'contract_no' => $contract->contract_no,
                'title' => $contract->title,
                'status' => $contract->status,
                'customer_id' => $contract->customer_id,
                'customer' => $contract->customer?->name,
                'starts_on' => $contract->starts_on->toDateString(),
                'ends_on' => $contract->ends_on->toDateString(),
                'pm_interval_months' => $contract->pm_interval_months,
                'asset_ids' => $contract->contractAssets->pluck('asset_id')->all(),
            ]])
            ->all();
    }
}
