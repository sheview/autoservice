<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The tenant of the current request or job (singleton).
 *
 * Setting it also:
 *  - on PostgreSQL, writes "app.tenant_id" on the database session, which is what the RLS
 *    policies read (cleared = '' so that no tenant row is visible)
 *  - switches spatie/laravel-permission to this tenant (team id, permission cache key)
 */
class TenantContext
{
    private ?int $id = null;

    private ?Tenant $tenant = null;

    private ?string $permissionCacheKey = null;

    public function set(Tenant|int|null $tenant): void
    {
        $this->tenant = $tenant instanceof Tenant ? $tenant : null;
        $this->id = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        if (Rls::supported()) {
            DB::select('select set_config(?, ?, false)', [
                Rls::SETTING,
                $this->id === null ? '' : (string) $this->id,
            ]);
        }

        $this->switchPermissions();
    }

    private function switchPermissions(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $this->permissionCacheKey ??= $registrar->cacheKey;

        $registrar->setPermissionsTeamId($this->id);
        // Roles are per tenant and RLS-filtered, so each tenant gets its own permission cache.
        $registrar->cacheKey = $this->permissionCacheKey.'.tenant.'.($this->id ?? 'none');
        $registrar->clearPermissionsCollection();
    }

    public function forget(): void
    {
        $this->set(null);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function check(): bool
    {
        return $this->id !== null;
    }

    public function tenant(): ?Tenant
    {
        if ($this->tenant === null && $this->id !== null) {
            $this->tenant = Tenant::find($this->id);
        }

        return $this->tenant;
    }

    /**
     * Run a callback as another tenant, then restore the previous one.
     */
    public function run(Tenant|int|null $tenant, callable $callback): mixed
    {
        $previous = $this->id;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
