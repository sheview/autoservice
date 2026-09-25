<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The tenant of the current request or job (singleton).
 *
 * Setting it also writes "app.tenant_id" on the database session, which is what the
 * RLS policies read. Clearing it writes '' so that no tenant row is visible.
 */
class TenantContext
{
    private ?int $id = null;

    private ?Tenant $tenant = null;

    public function set(Tenant|int|null $tenant): void
    {
        $this->tenant = $tenant instanceof Tenant ? $tenant : null;
        $this->id = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        DB::select('select set_config(?, ?, false)', [
            Rls::SETTING,
            $this->id === null ? '' : (string) $this->id,
        ]);
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
