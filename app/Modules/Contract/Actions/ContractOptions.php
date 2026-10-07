<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * The projects (MA contracts) another module's form can be for: the active ones, newest end date
 * first, plus the one already chosen (it may have ended since), labelled number · title · customer.
 * Given whom the form is for and the permission it needs: with scope "project", only the projects
 * whose team the user is on (all of them while the user is on no team).
 */
class ContractOptions
{
    /**
     * @return list<array{id: int, label: string}>
     */
    public function handle(?int $current = null, ?User $user = null, ?string $permission = null): array
    {
        $teamIds = $user !== null && $permission !== null && DataScope::of($user, $permission) === PermissionCatalog::SCOPE_PROJECT
            ? DataScope::projectIds($user) : [];

        return Contract::query()
            ->with('customer:id,name,short_name')
            ->where(fn (Builder $q) => $q
                ->where(fn ($q) => $q->where('status', Contract::STATUS_ACTIVE)->when($teamIds !== [], fn ($q) => $q->whereIn('id', $teamIds)))
                ->when($current, fn ($q) => $q->orWhere('id', $current)))
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
