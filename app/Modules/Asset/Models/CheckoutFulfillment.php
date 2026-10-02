<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hand-out of a line (a line may be handed out several times). For a part it points to the
 * stock movement that took it out of stock (Inventory module).
 */
class CheckoutFulfillment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['item_id', 'qty', 'fulfilled_by', 'fulfilled_by_name', 'fulfilled_at', 'stock_movement_id'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'fulfilled_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CheckoutItem::class, 'item_id');
    }
}
