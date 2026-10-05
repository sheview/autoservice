<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Equipment taken into (and back out of) the room, or out of it, on a request.
 */
class RoomAccessItem extends Model
{
    use BelongsToTenant;

    public const DIRECTIONS = ['in', 'out'];

    protected $fillable = ['request_id', 'position', 'name', 'serial_number', 'quantity', 'direction'];
}
