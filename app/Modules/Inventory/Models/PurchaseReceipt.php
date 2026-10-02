<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One delivery of a purchase request (a request may arrive in several). Holds what the buyer
 * read off the goods (brand, model, serials, price paid in satang) and, once registered, what it
 * became: assets (Asset module, one per serial or one holding the quantity; assets()) or stock of a
 * part (part_id + the stock movement).
 */
class PurchaseReceipt extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const AS_ASSET = 'asset';

    public const AS_PART = 'part';

    protected $fillable = [
        'purchase_request_id', 'quantity', 'brand', 'model', 'unit_price', 'serials', 'note',
        'received_by', 'received_by_name', 'received_at',
        'registered_as', 'part_id', 'stock_movement_id', 'registered_by_name', 'registered_at',
    ];

    protected $attributes = ['serials' => '[]'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'serials' => 'array',
            'received_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class)->withTrashed();
    }

    /** The assets it became, with the units each holds. */
    public function assets(): HasMany
    {
        return $this->hasMany(PurchaseReceiptAsset::class)->orderBy('id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }
}
