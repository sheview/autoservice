<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractPhase;

/**
 * Contracts that cover an asset, newest first, as plain arrays (for the Asset module).
 * "covering" = the contract is active and today is inside its period.
 */
class ContractsForAsset
{
    /**
     * @return list<array{id: int, contract_no: string, title: string, customer: string|null,
     *     starts_on: string, ends_on: string, phase: string, covering: bool, service_window: string}>
     */
    public function handle(int $assetId): array
    {
        return Contract::query()
            ->whereHas('contractAssets', fn ($q) => $q->where('asset_id', $assetId))
            ->with('customer:id,name')
            ->orderByDesc('ends_on')
            ->get()
            ->map(function (Contract $contract) {
                $phase = ContractPhase::of($contract);

                return [
                    'id' => $contract->id,
                    'contract_no' => $contract->contract_no,
                    'title' => $contract->title,
                    'customer' => $contract->customer?->name,
                    'starts_on' => $contract->starts_on->toDateString(),
                    'ends_on' => $contract->ends_on->toDateString(),
                    'phase' => $phase,
                    'covering' => in_array($phase, ['active', 'expiring'], true),
                    'service_window' => $contract->service_window,
                ];
            })
            ->all();
    }
}
