<?php

namespace App\Modules\Service\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A piece taken out of the customer's device on a job, as the technician noted it. Not stock.
 */
class TicketRemovedPart extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const DISPOSITIONS = ['claim', 'return_customer', 'keep', 'discard'];

    protected $fillable = ['ticket_id', 'asset_id', 'item_name', 'serial_number', 'problem', 'disposition', 'user_id', 'user_name'];
}
