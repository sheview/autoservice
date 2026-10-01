<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Events\TenantCreated;
use App\Modules\Tenancy\Support\CompanyProfile;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Tenant extends Model implements HasMedia
{
    use HasUlids, InteractsWithMedia, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['name', 'slug', 'subdomain', 'status', 'plan', 'is_platform', 'settings', 'subscription_starts_on', 'subscription_ends_on'];

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
            'subscription_starts_on' => 'date',
            'subscription_ends_on' => 'date',
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

    /** The company's logo (CompanyProfile), one image. */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(CompanyProfile::LOGO)->singleFile()->acceptsMimeTypes(CompanyProfile::LOGO_MIME_TYPES);
    }
}
