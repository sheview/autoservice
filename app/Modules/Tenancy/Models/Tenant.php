<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Events\TenantCreated;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasUlids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['name', 'slug', 'subdomain', 'status', 'plan', 'is_platform', 'settings'];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'plan' => 'standard',
        'is_platform' => false,
        'settings' => '{}',
    ];

    protected $dispatchesEvents = [
        'created' => TenantCreated::class,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_platform' => 'boolean',
        ];
    }

    /**
     * The primary key stays a bigint; only the "ulid" column is generated.
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
