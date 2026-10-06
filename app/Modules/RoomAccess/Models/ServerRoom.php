<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A customer's server room that our staff ask to enter. Its customer and site live in the
 * Contract module (read through its actions). The room's rules are its versions
 * (RoomRuleVersion); the newest one in effect is what a request accepts.
 */
class ServerRoom extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity, SoftDeletes;

    public const MISSING_BLOCK = 'block';

    public const MISSING_COMPANY_TERMS = 'company_terms';

    public const MISSING_RULES = [self::MISSING_BLOCK, self::MISSING_COMPANY_TERMS];

    public const ACCEPT_EVERY_REQUEST = 'every_request';

    public const ACCEPT_ONCE_PER_VERSION = 'once_per_version';

    public const ACCEPT_MODES = [self::ACCEPT_EVERY_REQUEST, self::ACCEPT_ONCE_PER_VERSION];

    protected $fillable = [
        'customer_id', 'site_id', 'name', 'location', 'requires_id_number', 'missing_rules', 'accept_mode', 'accept_on_enter',
        'entrants_accept_self', 'guard_link', 'freeze_periods', 'guard_contacts', 'is_active', 'notes',
    ];

    protected $attributes = [
        'requires_id_number' => false,
        'missing_rules' => self::MISSING_BLOCK,
        // Accepting a version once is enough unless the room asks for every request.
        'accept_mode' => self::ACCEPT_ONCE_PER_VERSION,
        'accept_on_enter' => false,
        'entrants_accept_self' => false,
        'guard_link' => false,
        'freeze_periods' => '[]',
        'guard_contacts' => '[]',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'requires_id_number' => 'boolean',
            'accept_on_enter' => 'boolean',
            'entrants_accept_self' => 'boolean',
            'guard_link' => 'boolean',
            'freeze_periods' => 'array',
            'guard_contacts' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function ruleVersions(): HasMany
    {
        // No ordering here: the relation is also counted (withCount). Callers sort.
        return $this->hasMany(RoomRuleVersion::class);
    }

    /** The rules in effect today: the newest version whose day has come. */
    public function currentRules(): HasOne
    {
        return $this->hasOne(RoomRuleVersion::class)->ofMany(['version' => 'max'], fn ($q) => $q->where('effective_on', '<=', now()->toDateString()));
    }

    public function approvalSteps(): HasMany
    {
        return $this->hasMany(RoomApprovalStep::class)->orderBy('position');
    }

    public function managers(): HasMany
    {
        return $this->hasMany(ServerRoomManager::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
