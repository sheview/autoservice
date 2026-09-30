<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Policies\UserPolicy;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[UseFactory(UserFactory::class)]
#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes {
        HasRoles::checkPermissionTo as protected checkRolePermissionTo;
    }

    public const SERVICE_LINES = ['network', 'pc', 'datacenter'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'branch_id',
        'customer_id',
        'employee_code',
        'position',
        'phone',
        'service_lines',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'service_lines' => '[]',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'service_lines' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Every permission check of the app ends here ($user->can('x.y') and the policies).
     * A platform user working inside a customer tenant has no role there: what they may do
     * comes from their role in the platform tenant, which Impersonation worked out.
     */
    public function checkPermissionTo($permission, $guardName = null): bool
    {
        $impersonation = app(Impersonation::class);

        if (is_string($permission) && $impersonation->actingAs($this)) {
            return $impersonation->allows($permission);
        }

        return $this->checkRolePermissionTo($permission, $guardName);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'branch_id', 'customer_id', 'employee_code', 'position', 'phone', 'service_lines', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
