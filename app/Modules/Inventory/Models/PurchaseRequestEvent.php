<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A change of status of a purchase request: what was done (PurchaseWorkflow action), from and
 * to which status, by whom and when, with the note given. Written once, never changed.
 */
class PurchaseRequestEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['purchase_request_id', 'action', 'from_status', 'to_status', 'actor_id', 'actor_name', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
