<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractPhase;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;

/**
 * The contracts of some customers whose assets the user may change, for the Asset module (the
 * asset page and form): what contracts.update reaches (ContractScope), as the contract page asks
 * (update + assets.view). Running only = active and not ended yet (upcoming, running, expiring),
 * what a new asset is put into; otherwise ended ones too, which an asset may still be taken out of.
 */
class EditableContracts
{
    /**
     * By customer id.
     *
     * @param  list<int>  $customerIds
     * @return array<int, list<array{id: int, contract_no: string, title: string, starts_on: string, ends_on: string, phase: string, covering: bool}>>
     */
    public function handle(User $user, array $customerIds, bool $runningOnly = true): array
    {
        if ($customerIds === [] || ! $user->can('contracts.update') || ! $user->can('assets.view')) {
            return [];
        }

        return ContractScope::contracts(Contract::query(), $user, 'contracts.update')
            ->whereIn('customer_id', $customerIds)
            ->when($runningOnly, fn ($q) => $q->where('status', Contract::STATUS_ACTIVE)->where('ends_on', '>=', now()->toDateString()))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get(['id', 'customer_id', 'contract_no', 'title', 'status', 'starts_on', 'ends_on', 'notify_days_before'])
            ->groupBy('customer_id')
            ->map(fn ($contracts) => $contracts->map(function (Contract $contract) {
                $phase = ContractPhase::of($contract);

                return [
                    'id' => $contract->id,
                    'contract_no' => $contract->contract_no,
                    'title' => $contract->title,
                    'starts_on' => $contract->starts_on->toDateString(),
                    'ends_on' => $contract->ends_on->toDateString(),
                    'phase' => $phase,
                    'covering' => in_array($phase, ['active', 'expiring'], true),
                ];
            })->values()->all())
            ->all();
    }
}
