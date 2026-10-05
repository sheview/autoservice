<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One of our users who looks after a room (may record entering and leaving for others).
 */
class ServerRoomManager extends Model
{
    use BelongsToTenant;

    protected $fillable = ['server_room_id', 'user_id'];
}
