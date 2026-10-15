<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link that lets a customer see (and download) a published PM report without an account.
 * Only the SHA-256 of its token is stored (ShareToken); the link is shown once, when made.
 * Pinned to one version, or (pm_report_version_id null) the newest published one.
 */
class PmReportShare extends Model
{
    use BelongsToTenant;

    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 365;

    protected $fillable = [
        'pm_visit_id', 'pm_report_version_id', 'token_hash', 'recipient_name', 'recipient_email', 'allow_download',
        'expires_at', 'revoked_at', 'revoked_by_name', 'view_count', 'last_viewed_at', 'created_by', 'created_by_name',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'allow_download' => 'boolean',
            'view_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PmVisit::class, 'pm_visit_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PmReportVersion::class, 'pm_report_version_id');
    }

    /** Not revoked and not expired (whether the report may still be shown is checked apart). */
    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
