<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Evidence that a room's rules were accepted: who, when, from which address and device, which
 * version, and a copy of the text as shown then. Never changed.
 */
class RoomRuleAcceptance extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public const CONTEXT_SUBMIT = 'submit';

    public const CONTEXT_ENTER = 'enter';

    public const CONTEXT_SELF = 'self';

    protected $fillable = [
        'server_room_id', 'request_id', 'rule_version_id', 'version', 'context', 'user_id', 'person_id', 'accepted_by_name',
        'on_behalf_of_team', 'snapshot', 'ip', 'user_agent', 'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'on_behalf_of_team' => 'boolean',
            'snapshot' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Rule acceptances are evidence and are never changed.');
        });
    }
}
