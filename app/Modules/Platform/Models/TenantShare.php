<?php

namespace App\Modules\Platform\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One company (from) may use some abilities on another company's (to) data. Platform data with
 * no tenant scope: use it only through ShareGateway and the Platform share actions.
 */
class TenantShare extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /**
     * What can be shared: seeing parts / assets, and asking for them (an issue/loan request made in
     * the owner company, approved by its approver), and sending tickets on to be done there.
     */
    public const ABILITIES = ['parts.view', 'assets.view', 'parts.request', 'assets.request', 'tickets.forward'];

    /** Roles of the platform that may use a share while working inside the "from" company. */
    public const CENTRAL_ROLES = ['central_technician', 'central_helpdesk'];

    protected $fillable = [
        'from_tenant_id', 'to_tenant_id', 'abilities', 'roles', 'user_ids', 'branch_ids', 'status', 'reason', 'expires_on',
        'granted_by_name', 'accepted_by_name', 'accepted_at', 'revoked_by_name', 'revoked_at',
    ];

    protected $attributes = ['abilities' => '[]', 'roles' => '[]', 'user_ids' => '[]', 'branch_ids' => '[]'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'roles' => 'array',
            'user_ids' => 'array',
            'branch_ids' => 'array',
            'expires_on' => 'date',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function fromTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'from_tenant_id');
    }

    public function toTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'to_tenant_id');
    }
}
