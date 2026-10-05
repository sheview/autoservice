<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One step of the approval of a room's requests, in order. Side "company": a named user of ours,
 * or (no one named) anyone holding room-access.approve; never the requester. Side "customer"
 * (approving through a link) is kept for later.
 */
class RoomApprovalStep extends Model
{
    use BelongsToTenant;

    public const SIDE_COMPANY = 'company';

    public const SIDE_CUSTOMER = 'customer';

    protected $fillable = ['server_room_id', 'position', 'side', 'approver_user_id', 'approver_name', 'approver_email'];
}
