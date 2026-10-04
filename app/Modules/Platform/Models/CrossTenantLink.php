<?php

namespace App\Modules\Platform\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document of one company tied to a document of another (a ticket of A and its request in B).
 * Platform data with no tenant scope: use it only through App\Modules\Platform\CrossTenant.
 */
class CrossTenantLink extends Model
{
    public const SOURCE_TICKET = 'ticket';

    public const TARGET_CHECKOUT = 'checkout_request';

    protected $fillable = [
        'source_tenant_id', 'source_type', 'source_id', 'source_label',
        'target_tenant_id', 'target_type', 'target_id', 'target_label',
        'created_by_id', 'created_by_name',
    ];

    public function sourceTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'source_tenant_id');
    }

    public function targetTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'target_tenant_id');
    }
}
