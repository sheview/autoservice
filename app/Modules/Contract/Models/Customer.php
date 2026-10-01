<?php

namespace App\Modules\Contract\Models;

use App\Modules\Contract\Policies\CustomerPolicy;
use App\Modules\Document\Concerns\HasAttachments;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A customer of the MA company (the tenant), with its documents (company certificate, VAT
 * registration, ...) attached through HasAttachments.
 */
#[UsePolicy(CustomerPolicy::class)]
class Customer extends Model implements HasMedia
{
    use BelongsToTenant, HasAttachments, InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $fillable = ['code', 'name', 'short_name', 'tax_id', 'contact_name', 'phone', 'email', 'address', 'notes'];

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function registerMediaCollections(): void
    {
        $this->registerAttachmentCollection();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
