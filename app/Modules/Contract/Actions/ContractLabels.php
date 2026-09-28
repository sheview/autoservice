<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;

/**
 * Number and title of contracts by id (deleted ones too), for other modules showing a reference.
 */
class ContractLabels
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, contract_no: string, title: string}>
     */
    public function handle(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : Contract::withTrashed()->whereKey($ids)->get(['id', 'contract_no', 'title'])
            ->mapWithKeys(fn (Contract $contract) => [$contract->id => $contract->only(['id', 'contract_no', 'title'])])
            ->all();
    }
}
