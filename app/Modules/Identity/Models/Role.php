<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Policies\RolePolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Roles belong to a tenant, so each tenant can adjust its own roles.
 * System roles (seeded defaults) cannot be renamed.
 */
#[UsePolicy(RolePolicy::class)]
class Role extends SpatieRole
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }
}
