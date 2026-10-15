<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * The days running contracts start and end between two days, for the "my work" calendar of the
 * Service module. Only the contracts within reach of the user's contracts.view (ContractScope);
 * drafts and cancelled ones never run, so they are left out.
 */
class ContractMilestones
{
    /**
     * @return list<array{id: int, contract_no: string, title: string, customer: string|null, date: string, milestone: 'start'|'end', href: string}>
     */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $between = [$from->toDateString(), $to->toDateString()];

        return ContractScope::contracts(Contract::query(), $user)
            ->where('status', Contract::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereBetween('starts_on', $between)->orWhereBetween('ends_on', $between))
            ->with('customer:id,name')
            ->get(['id', 'customer_id', 'contract_no', 'title', 'starts_on', 'ends_on'])
            ->flatMap(fn (Contract $contract) => collect(['start' => $contract->starts_on, 'end' => $contract->ends_on])
                ->filter(fn ($on) => $on && $on->toDateString() >= $between[0] && $on->toDateString() <= $between[1])
                ->map(fn ($on, string $milestone) => [
                    'id' => $contract->id,
                    'contract_no' => $contract->contract_no,
                    'title' => $contract->title,
                    'customer' => $contract->customer?->name,
                    'date' => $on->toDateString(),
                    'milestone' => $milestone,
                    'href' => route('contract.contracts.show', $contract),
                ])
                ->values())
            ->values()
            ->all();
    }
}
