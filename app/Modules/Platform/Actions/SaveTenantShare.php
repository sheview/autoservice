<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Tenancy\Models\Tenant;

/**
 * The superadmin sets what company $from may see of company $to. "activate" puts it in force
 * at once; otherwise it waits for $to's admin to accept (a changed share asks again, unless
 * activated). A revoked share comes back through here.
 */
class SaveTenantShare
{
    /**
     * @param  array{abilities: list<string>, roles: list<string>, reason?: string|null, expires_on?: string|null, activate: bool}  $data  validated
     */
    public function handle(Tenant $from, Tenant $to, array $data, User $superadmin): TenantShare
    {
        $share = TenantShare::firstOrNew(['from_tenant_id' => $from->id, 'to_tenant_id' => $to->id]);

        $share->fill([
            'abilities' => array_values(array_unique($data['abilities'])),
            'roles' => array_values(array_unique($data['roles'])),
            'reason' => $data['reason'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'granted_by_name' => $superadmin->name,
            'revoked_by_name' => null,
            'revoked_at' => null,
        ]);
        $share->status = $data['activate'] ? TenantShare::STATUS_ACTIVE : TenantShare::STATUS_PENDING;
        if ($data['activate']) {
            $share->accepted_by_name = $superadmin->name;
            $share->accepted_at = now();
        } else {
            $share->accepted_by_name = null;
            $share->accepted_at = null;
        }
        $share->save();

        activity('sharing')->event('share_saved')->withProperties([
            'from' => $from->name, 'to' => $to->name, 'abilities' => $share->abilities, 'status' => $share->status,
        ])->log('share_saved');

        return $share;
    }
}
