<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;

/**
 * The contract numbers each asset is in, for the Asset module's Excel export: only the contracts
 * the user may see (contracts.view, ContractScope), oldest first.
 */
class ContractNumbersByAsset
{
    /**
     * @return array<int, list<string>> asset id => contract numbers
     */
    public function handle(User $user): array
    {
        if (! $user->can('contracts.view')) {
            return [];
        }

        // Contracts of this tenant (tenant scope) the user may see; their rows of contract_assets.
        return ContractScope::contracts(Contract::query(), $user)
            ->join('contract_assets', 'contract_assets.contract_id', '=', 'contracts.id')
            ->orderBy('contracts.starts_on')
            ->orderBy('contracts.id')
            ->toBase()
            ->get(['contract_assets.asset_id', 'contracts.contract_no'])
            ->groupBy('asset_id')
            ->map(fn ($rows) => $rows->pluck('contract_no')->all())
            ->all();
    }
}
