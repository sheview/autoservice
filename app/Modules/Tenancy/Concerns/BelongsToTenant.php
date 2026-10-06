<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // A row is written into the current tenant only, never another one (what row level
        // security's WITH CHECK did on PostgreSQL): switch with TenantContext::run() to write elsewhere.
        static::creating(function (Model $model) {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                throw new LogicException('Cannot create '.$model::class.' without a tenant context.');
            }
            if ($model->getAttribute('tenant_id') !== null && (int) $model->getAttribute('tenant_id') !== $tenantId) {
                throw new LogicException('Cannot create '.$model::class.' in another tenant than the current one.');
            }

            $model->setAttribute('tenant_id', $tenantId);
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('A '.$model::class.' never moves to another tenant.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
