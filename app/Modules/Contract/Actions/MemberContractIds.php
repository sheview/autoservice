<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\ContractMember;

/**
 * The projects (contract ids) whose team a user is on, in the current tenant: what scope
 * "project" reaches (DataScope of the Identity module).
 */
class MemberContractIds
{
    /**
     * @return list<int>
     */
    public function handle(int $userId): array
    {
        return ContractMember::query()
            ->where('user_id', $userId)
            ->pluck('contract_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
