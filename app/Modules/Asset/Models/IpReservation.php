<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An address held for a job: who, when, what for. "active" until the address is given to a
 * device ("used") or let go ("released").
 */
class IpReservation extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_USED = 'used';

    public const STATUS_RELEASED = 'released';

    protected $fillable = ['ip_address_id', 'reserved_by', 'reserved_at', 'purpose', 'notes', 'status', 'ended_at'];

    protected function casts(): array
    {
        return ['reserved_at' => 'datetime', 'ended_at' => 'datetime', 'reserved_by' => 'integer'];
    }
}
