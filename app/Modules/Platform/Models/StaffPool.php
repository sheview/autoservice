<?php

namespace App\Modules\Platform\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The people of one company (from) with one of the roles also work for another (to): each gets a
 * linked account there (SyncStaffPool). Platform data with no tenant scope, set by the superadmin.
 */
class StaffPool extends Model
{
    public const DEFAULT_ROLES = ['technician'];

    protected $fillable = ['from_tenant_id', 'to_tenant_id', 'roles', 'is_active', 'created_by_name'];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'is_active' => 'boolean',
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
