<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\TenantShare;
use Illuminate\Validation\ValidationException;

/**
 * Accepts a pending share (the receiving company's admin) or revokes one (either company's
 * admin, or the superadmin). Revoking stops access at once; what was done stays.
 */
class DecideTenantShare
{
    public function handle(TenantShare $share, string $decision, User $user): TenantShare
    {
        if ($decision === 'accept') {
            if ($share->status !== TenantShare::STATUS_PENDING) {
                throw ValidationException::withMessages(['share' => __('platform.shares.not_pending')]);
            }
            $share->update(['status' => TenantShare::STATUS_ACTIVE, 'accepted_by_name' => $user->name, 'accepted_at' => now()]);
        } else {
            $share->update(['status' => TenantShare::STATUS_REVOKED, 'revoked_by_name' => $user->name, 'revoked_at' => now()]);
        }

        activity('sharing')->event("share_{$decision}")->withProperties([
            'from' => $share->fromTenant?->name, 'to' => $share->toTenant?->name,
        ])->log("share_{$decision}");

        return $share;
    }
}
