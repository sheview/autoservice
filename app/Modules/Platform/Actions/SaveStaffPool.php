<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Models\StaffPool;
use Illuminate\Validation\ValidationException;

/**
 * Sets up or changes a staff pool ("the technicians of A also work for B") and brings the linked
 * accounts in step right away (SyncStaffPool).
 */
class SaveStaffPool
{
    public function __construct(private SyncStaffPool $sync) {}

    /**
     * @param  array{from_tenant_id?: int, to_tenant_id?: int, roles: list<string>, is_active?: bool}  $data
     */
    public function handle(?StaffPool $pool, array $data, string $actorName): StaffPool
    {
        if ($pool === null) {
            if (StaffPool::where('from_tenant_id', $data['from_tenant_id'])->where('to_tenant_id', $data['to_tenant_id'])->exists()) {
                throw ValidationException::withMessages(['to_tenant_id' => __('platform.staff_pools.exists')]);
            }
            $pool = new StaffPool([
                'from_tenant_id' => $data['from_tenant_id'],
                'to_tenant_id' => $data['to_tenant_id'],
                'created_by_name' => $actorName,
            ]);
        }

        $pool->fill([
            'roles' => array_values(array_unique($data['roles'])),
            'is_active' => $data['is_active'] ?? $pool->is_active ?? true,
        ])->save();

        $this->sync->handle($pool);

        return $pool;
    }
}
