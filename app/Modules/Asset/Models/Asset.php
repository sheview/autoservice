<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Policies\AssetPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An asset (device) of the tenant. Public URLs use "ulid"; purchase_price is in satang.
 */
#[UsePolicy(AssetPolicy::class)]
class Asset extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity, SoftDeletes;

    public const STATUS_IN_USE = 'in_use';

    public const STATUS_SPARE = 'spare';

    public const STATUS_IN_REPAIR = 'in_repair';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_IN_USE, self::STATUS_SPARE, self::STATUS_IN_REPAIR, self::STATUS_RETIRED];

    protected $fillable = [
        'branch_id',
        'customer_id',
        'category_id',
        'asset_code',
        'name',
        'brand',
        'model',
        'serial_number',
        'status',
        'location',
        'purchased_at',
        'purchase_price',
        'warranty_expires_at',
        'specs',
        'notes',
    ];

    protected $attributes = [
        'status' => self::STATUS_IN_USE,
        'specs' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'warranty_expires_at' => 'date',
            'purchase_price' => 'integer',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'branch_id', 'customer_id', 'category_id', 'asset_code', 'name', 'brand', 'model', 'serial_number', 'status',
                'location', 'purchased_at', 'purchase_price', 'warranty_expires_at', 'specs', 'notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
