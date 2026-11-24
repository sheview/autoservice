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

    /** How the company's staff sign in (customers' accounts always use a password). */
    public const AUTH_PASSWORD = 'password';

    public const AUTH_BOTH = 'both';

    public const AUTH_SSO_ONLY = 'sso_only';

    public const AUTH_MODES = [self::AUTH_PASSWORD, self::AUTH_BOTH, self::AUTH_SSO_ONLY];

    /** Where its SSO accounts live: in Keycloak (made by Governix), or the company's own system via a Keycloak Organization. */
    public const SSO_KEYCLOAK = 'keycloak';

    public const SSO_IDP = 'idp';

    public const SSO_SOURCES = [self::SSO_KEYCLOAK, self::SSO_IDP];

    protected $fillable = ['name', 'slug', 'subdomain', 'status', 'plan', 'is_platform', 'settings', 'subscription_starts_on', 'subscription_ends_on', 'auth_mode', 'sso_source', 'keycloak_org'];

    protected $attributes = [
        'auth_mode' => self::AUTH_PASSWORD,
        'sso_source' => self::SSO_KEYCLOAK,
        'status' => self::STATUS_ACTIVE,
        'plan' => 'standard',
        'is_platform' => false,
        'is_sample' => false,
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
            'is_sample' => 'boolean',
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
