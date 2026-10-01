<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Policies\AssetCategoryPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * requires_serial: assets of this category are devices and need at least one serial number;
 * the others are counted by quantity and unit.
 *
 * @property list<array{key: string, label: string, type: string, options?: list<string>, required?: bool}> $spec_fields
 */
#[UsePolicy(AssetCategoryPolicy::class)]
class AssetCategory extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    public const SERVICE_LINES = ['network', 'pc', 'datacenter'];

    public const FIELD_TYPES = ['text', 'number', 'date', 'select'];

    public const TYPE_HARDWARE = 'hardware';

    public const TYPE_SOFTWARE = 'software';

    /** Every asset takes the type of its category. */
    public const ASSET_TYPES = [self::TYPE_HARDWARE, self::TYPE_SOFTWARE];

    protected $fillable = ['name', 'code_prefix', 'service_line', 'asset_type', 'requires_serial', 'spec_fields'];

    protected $attributes = [
        'asset_type' => self::TYPE_HARDWARE,
        'requires_serial' => false,
        'spec_fields' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'requires_serial' => 'boolean',
            'spec_fields' => 'array',
        ];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'category_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code_prefix', 'service_line', 'asset_type', 'requires_serial', 'spec_fields'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
