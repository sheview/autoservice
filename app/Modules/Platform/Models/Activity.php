<?php

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Activity log entry of a tenant.
 *
 * Every entry also stores who really did it (name + email) in properties.actor, because the
 * causer may live in another tenant (a superadmin impersonating) and is then not readable
 * through RLS from this tenant.
 */
class Activity extends SpatieActivity
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (Activity $activity) {
            $causer = $activity->causer;
            if (! $causer instanceof Authenticatable) {
                return;
            }

            $impersonation = app(Impersonation::class);

            $activity->properties = $activity->properties->merge([
                'actor' => [
                    'id' => $causer->getAuthIdentifier(),
                    'name' => $causer->name,
                    'email' => $causer->email,
                    'tenant_id' => $causer->tenant_id,
                ],
                'impersonating' => $impersonation->active(),
            ]);
        });
    }
}
