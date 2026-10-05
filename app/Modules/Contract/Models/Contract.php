<?php

namespace App\Modules\Contract\Models;

use App\Modules\Contract\Policies\ContractPolicy;
use App\Modules\Document\Concerns\HasAttachments;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * An MA contract with a customer. value is in satang. The covered assets are in contract_assets
 * (ContractAsset); the files (signed contract, appendix) are the "documents" media collection.
 */
#[UsePolicy(ContractPolicy::class)]
class Contract extends Model implements HasMedia
{
    use BelongsToTenant, HasAttachments, InteractsWithMedia, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_CANCELLED];

    public const SERVICE_WINDOWS = ['8x5', '12x6', '24x7'];

    public const PM_INTERVALS = [1, 2, 3, 4, 6, 12];

    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    public const DOCUMENTS = 'documents';

    protected $fillable = [
        'customer_id', 'contract_no', 'title', 'status', 'starts_on', 'ends_on', 'value',
        'service_window', 'pm_interval_months', 'notify_days_before', 'expiry_notified_at', 'notes', 'require_signature',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'service_window' => '8x5',
        'notify_days_before' => 60,
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'value' => 'integer',
            'pm_interval_months' => 'integer',
            'notify_days_before' => 'integer',
            'expiry_notified_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function slas(): HasMany
    {
        return $this->hasMany(ContractSla::class);
    }

    public function contractAssets(): HasMany
    {
        return $this->hasMany(ContractAsset::class);
    }

    public function registerMediaCollections(): void
    {
        $this->registerAttachmentCollection();
    }

    /** Contract files were here before HasAttachments, so they keep their collection. */
    public function attachmentCollection(): string
    {
        return self::DOCUMENTS;
    }

    /** A signed contract is often a scan. */
    public function attachmentsTakeImages(): bool
    {
        return true;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'customer_id', 'contract_no', 'title', 'status', 'starts_on', 'ends_on', 'value',
                'service_window', 'pm_interval_months', 'notify_days_before', 'notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
