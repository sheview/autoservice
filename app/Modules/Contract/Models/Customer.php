<?php

namespace App\Modules\Contract\Models;

use App\Modules\Contract\Policies\CustomerPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A customer of the MA company (the tenant).
 */
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model
{
    use BelongsToTenant, LogsActivity, SoftDeletes;

    protected $fillable = ['code', 'name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'notes'];

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
