<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hand-out of a line (a line may be handed out several times). For a part it points to the
 * stock movement that took it out of stock (Inventory module). The receiver confirms it
 * (received_at) or says what was wrong with it (receipt_issue): ConfirmCheckoutReceipt.
 */
class CheckoutFulfillment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['item_id', 'qty', 'fulfilled_by', 'fulfilled_by_name', 'fulfilled_at', 'stock_movement_id',
        'received_at', 'received_by', 'received_by_name', 'receipt_issue', 'receipt_alerted_at'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'fulfilled_at' => 'datetime', 'received_at' => 'datetime', 'receipt_alerted_at' => 'datetime'];
    }

    /** Handed out and not confirmed yet (nor reported as wrong). */
    public function awaitsReceipt(): bool
    {
        return $this->received_at === null && $this->receipt_issue === null;
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CheckoutItem::class, 'item_id');
    }
}
