<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone a requester has taken into a room before, offered again on their next request (only to
 * them). Name, company and phone only: never an ID number.
 */
class RoomVisitor extends Model
{
    use BelongsToTenant;

    protected $fillable = ['owner_id', 'name', 'company', 'phone', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }
}
