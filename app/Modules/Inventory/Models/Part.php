<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Document\Concerns\HasPhotoSlots;
use App\Modules\Inventory\Policies\PartPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;

/**
 * A spare part or consumable the MA company keeps in stock. "qty_on_hand" is changed only by
 * RecordStockMovement, which writes the matching row of the ledger (stock_movements).
 * It carries up to four photos (HasPhotoSlots).
 */
#[UsePolicy(PartPolicy::class)]
class Part extends Model implements HasMedia
{
    use BelongsToTenant, HasPhotoSlots, LogsActivity, SoftDeletes;

    protected $fillable = ['code', 'name', 'contract_id', 'part_number', 'brand', 'unit', 'min_qty', 'unit_cost', 'is_active', 'notes'];

    protected $attributes = [
        'min_qty' => 0,
        'qty_on_hand' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'min_qty' => 'integer',
            'unit_cost' => 'integer',
            'qty_on_hand' => 'integer',
            'is_active' => 'boolean',
            'low_stock_notified_at' => 'datetime',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** At or below the reorder point (a reorder point of 0 means the part is not watched). */
    public function isLow(): bool
    {
        return $this->min_qty > 0 && $this->qty_on_hand <= $this->min_qty;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
