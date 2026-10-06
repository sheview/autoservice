<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time the team went into the room on a request and came out (still inside while exited_at is
 * empty), with who recorded each.
 */
class RoomAccessVisit extends Model
{
    use BelongsToTenant;

    protected $fillable = ['request_id', 'entered_at', 'entered_by_name', 'exited_at', 'exited_by_name'];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime', 'exited_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RoomAccessRequest::class, 'request_id')->withTrashed();
    }
}
