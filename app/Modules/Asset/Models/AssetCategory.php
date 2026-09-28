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
 * @property list<array{key: string, label: string, type: string, options?: list<string>, required?: bool}> $spec_fields
 */
#[UsePolicy(AssetCategoryPolicy::class)]
class AssetCategory extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    public const SERVICE_LINES = ['network', 'pc', 'datacenter'];

    public const FIELD_TYPES = ['text', 'number', 'date', 'select'];

    protected $fillable = ['name', 'code_prefix', 'service_line', 'spec_fields'];

    protected $attributes = [
        'spec_fields' => '[]',
    ];

    protected function casts(): array
    {
        return [
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
            ->logOnly(['name', 'code_prefix', 'service_line', 'spec_fields'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
