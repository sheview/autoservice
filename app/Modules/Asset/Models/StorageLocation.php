<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Policies\StorageLocationPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Where the company keeps its things (main store, shelf A-1, ...), for assets and parts alike.
 * One that is not active is no longer offered, but what is kept there keeps it.
 */
#[UsePolicy(StorageLocationPolicy::class)]
class StorageLocation extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    protected $fillable = ['name', 'notes', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
