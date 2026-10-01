<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

/**
 * The projects (MA contracts) another module's form can be for: the active ones, newest end date
 * first, plus the one already chosen (it may have ended since), labelled number · title · customer.
 */
class ContractOptions
{
    /**
     * @return list<array{id: int, label: string}>
     */
    public function handle(?int $current = null): array
    {
        return Contract::query()
            ->with('customer:id,name,short_name')
            ->where(fn (Builder $q) => $q->where('status', Contract::STATUS_ACTIVE)->when($current, fn ($q) => $q->orWhere('id', $current)))
            ->orderByDesc('ends_on')
            ->orderBy('contract_no')
            ->get(['id', 'customer_id', 'contract_no', 'title'])
            ->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'label' => collect([
                    $contract->contract_no,
                    $contract->title,
                    filled($contract->customer?->short_name) ? $contract->customer->short_name : $contract->customer?->name,
                ])->filter()->implode(' · '),
            ])
            ->values()
            ->all();
    }
}
