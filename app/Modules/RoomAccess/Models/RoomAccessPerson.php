<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone going into the room on a request. The ID card number (only when the room asks) is kept
 * encrypted, shown masked (IdNumber::mask) and deleted after the company's retention.
 */
class RoomAccessPerson extends Model
{
    use BelongsToTenant;

    protected $table = 'room_access_people';

    protected $fillable = ['request_id', 'position', 'name', 'company', 'phone', 'id_number'];

    protected $hidden = ['id_number'];

    protected function casts(): array
    {
        return ['id_number' => 'encrypted'];
    }
}
