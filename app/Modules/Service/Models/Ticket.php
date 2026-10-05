<?php

namespace App\Modules\Service\Models;

use App\Modules\Document\Concerns\HasAttachments;
use App\Modules\Service\Policies\TicketPolicy;
use App\Modules\Service\Support\TicketNumber;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A service job (ticket). Public URLs use "ulid". The SLA values are copied from the contract
 * when the ticket is opened; contract_id null = out of contract (no SLA).
 * Customer, asset, contract and users belong to other modules and are read through their actions.
 * Files (quotations, logs, reports) are attached through HasAttachments.
 */
#[UsePolicy(TicketPolicy::class)]
class Ticket extends Model implements HasMedia
{
    use BelongsToTenant, HasAttachments, HasUlids, InteractsWithMedia, SoftDeletes;

    /** Reported by a customer with the QR form: the helpdesk checks it before it joins the queue. */
    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_NEW = 'new';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING_REVIEW, self::STATUS_NEW, self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_ON_HOLD,
        self::STATUS_RESOLVED, self::STATUS_CLOSED, self::STATUS_CANCELLED,
    ];

    /** Statuses in which the job is still running (not done or cancelled). */
    public const OPEN_STATUSES = [self::STATUS_NEW, self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_ON_HOLD];

    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /** pm = found during a PM round (Maintenance module). */
    public const SOURCES = ['phone', 'email', 'walk_in', 'portal', 'pm'];

    /** Sent on by another company through a share (OpenForwardedTicket); never picked on a form. */
    public const SOURCE_PARTNER = 'partner';

    protected $fillable = [
        'ticket_no', 'customer_id', 'asset_id', 'ip_address_id', 'contract_id', 'branch_id', 'title', 'description',
        'priority', 'status', 'source', 'contact_name', 'contact_phone', 'contact_email', 'customer_message', 'closed_lat', 'closed_lng', 'reported_by', 'assignee_id', 'appointment_at',
        'service_window', 'response_minutes', 'resolve_minutes', 'response_due_at', 'resolve_due_at',
        'responded_at', 'response_breach_notified_at', 'resolve_breach_notified_at', 'on_hold_since', 'hold_minutes', 'resolved_at', 'closed_at', 'cancelled_at',
        'device_name', 'device_brand', 'device_model', 'device_serial', 'device_serial_unknown', 'device_location', 'device_ip',
        'warranty_status', 'warranty_expires_on', 'warranty_checked_by_name', 'warranty_checked_at',
        // Repair report of the job sheet (SaveRepairReport); extra_cost in satang.
        'cause', 'extra_cost', 'approver_name',
    ];

    /** What staff found when they checked the device's warranty before starting work. */
    public const WARRANTY_STATUSES = ['in_warranty', 'out_of_warranty'];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'priority' => 'medium',
        'source' => 'phone',
        'hold_minutes' => 0,
    ];

    /**
     * Read, the number is shown with the company code (TK001-2569-00001, TicketNumber) everywhere it
     * goes out; stored and queried it stays the company's own number (TK-2569-00001, unique per tenant).
     * The stored one: getRawOriginal('ticket_no').
     */
    protected function ticketNo(): Attribute
    {
        return Attribute::get(fn (?string $value, array $attributes) => $value === null ? null
            // Read without its tenant_id (a narrow select): it is of the company being worked in.
            : TicketNumber::shown($value, isset($attributes['tenant_id']) ? (int) $attributes['tenant_id'] : app(TenantContext::class)->id()));
    }

    protected function casts(): array
    {
        return [
            'response_minutes' => 'integer',
            'resolve_minutes' => 'integer',
            'hold_minutes' => 'integer',
            'response_due_at' => 'datetime',
            'resolve_due_at' => 'datetime',
            'appointment_at' => 'datetime',
            'responded_at' => 'datetime',
            'response_breach_notified_at' => 'datetime',
            'resolve_breach_notified_at' => 'datetime',
            'on_hold_since' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'device_serial_unknown' => 'boolean',
            'extra_cost' => 'integer',
            'warranty_expires_on' => 'date',
            'warranty_checked_at' => 'datetime',
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

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Photos taken on site when closing (custom property "stage": before | after), and the customer's signature. */
    public const PHOTOS = 'repair_photos';

    public const SIGNATURE = 'signature';

    public function registerMediaCollections(): void
    {
        $this->registerAttachmentCollection();
        $this->addMediaCollection(self::PHOTOS)->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
        $this->addMediaCollection(self::SIGNATURE)->singleFile()->acceptsMimeTypes(['image/png']);
    }
}
