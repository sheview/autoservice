<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Use on every queued job.
 *
 * TenancyServiceProvider writes the current tenant_id into the queue payload when the job
 * is dispatched, and restores TenantContext (and app.tenant_id on the DB session) before
 * handle() runs. After the job finishes the worker's previous context is restored.
 */
trait InteractsWithTenant
{
    protected function tenant(): ?Tenant
    {
        return app(TenantContext::class)->tenant();
    }
}
