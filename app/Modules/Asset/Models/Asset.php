<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Policies\AssetPolicy;
use App\Modules\Document\Concerns\HasAttachments;
use App\Modules\Document\Concerns\HasPhotoSlots;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;

/**
 * An asset (device) of the tenant. Public URLs use "ulid"; purchase_price is in satang;
 * property_no is the owner's equipment number (เลขครุภัณฑ์), optional and unique.
 * Serial numbers are in "serials"; serial_number keeps the first one for lists, labels and tickets.
 * quantity is the number of serials when the category requires them, otherwise typed in with a unit.
 * brand / model are the text shown; asset_brand_id / asset_model_id point at the same names in the
 * catalog (kept in step by SaveAsset). parent_asset_id ties a part to the asset it belongs to.
 * It carries up to four photos (HasPhotoSlots) and attached files (HasAttachments).
 */
#[UsePolicy(AssetPolicy::class)]
class Asset extends Model implements HasMedia
{
    use BelongsToTenant, HasAttachments, HasPhotoSlots, HasUlids, LogsActivity, SoftDeletes;

    public const STATUS_IN_USE = 'in_use';

    public const STATUS_SPARE = 'spare';

    public const STATUS_IN_REPAIR = 'in_repair';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_IN_USE, self::STATUS_SPARE, self::STATUS_IN_REPAIR, self::STATUS_RETIRED];

    protected $fillable = [
        'branch_id',
        'customer_id',
        'category_id',
        'parent_asset_id',
        'asset_code',
        'name',
        'brand',
        'asset_brand_id',
        'model',
        'asset_model_id',
        'subtype',
        'serial_number',
        'quantity',
        'unit',
        'property_no',
        'status',
        'location',
        'floor',
        'room',
        'ip_address',
        'mac_address',
        'used_by',
        'department',
        'purchased_at',
        'purchase_price',
        'warranty_expires_at',
        'specs',
        'notes',
    ];

    protected $attributes = [
        'status' => self::STATUS_IN_USE,
        'quantity' => 1,
        'specs' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'warranty_expires_at' => 'date',
            'purchase_price' => 'integer',
            'quantity' => 'integer',
            'specs' => 'array',
        ];
    }

    /**
     * The primary key stays a bigint; only the "ulid" column is generated.
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function registerMediaCollections(): void
    {
        $this->registerPhotoCollection();
        $this->registerAttachmentCollection();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id')->withTrashed();
    }

    public function serials(): HasMany
    {
        return $this->hasMany(AssetSerial::class)->orderBy('id');
    }

    /** The asset this one is part of (a monitor's PC, a battery cabinet's UPS). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id')->orderBy('asset_code');
    }

    public function catalogBrand(): BelongsTo
    {
        return $this->belongsTo(AssetBrand::class, 'asset_brand_id')->withTrashed();
    }

    public function catalogModel(): BelongsTo
    {
        return $this->belongsTo(AssetModel::class, 'asset_model_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'branch_id', 'customer_id', 'category_id', 'parent_asset_id', 'asset_code', 'name', 'brand', 'model', 'subtype', 'serial_number', 'quantity', 'unit',
                'property_no', 'status', 'location', 'floor', 'room', 'ip_address', 'mac_address', 'used_by', 'department',
                'purchased_at', 'purchase_price', 'warranty_expires_at', 'specs', 'notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
