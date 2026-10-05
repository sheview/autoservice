<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One change of a request's status: what was done, by whom, when, and the note given.
 */
class RoomAccessEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['request_id', 'action', 'from_status', 'to_status', 'actor_id', 'actor_name', 'note'];
}
