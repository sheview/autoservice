<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An asset (Asset module) a delivery became, with how many units it holds: 1 for a device of
 * its own, more for an asset that holds a quantity.
 */
class PurchaseReceiptAsset extends Model
{
    use BelongsToTenant;

    protected $fillable = ['purchase_receipt_id', 'asset_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
