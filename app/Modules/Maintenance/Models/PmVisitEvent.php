<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a work order's history (LogPmEvent), kept for good and never changed. user_id is
 * null for the system and for a customer opening a share link.
 */
class PmVisitEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** A move of the workflow is logged under its action (PmWorkflow::ACTIONS); these are the others. */
    public const TYPE_CREATED = 'created';

    public const TYPE_UPDATED = 'updated';

    public const TYPE_ITEM_ADDED = 'item_added';

    public const TYPE_ITEM_REMOVED = 'item_removed';

    public const TYPE_SIGNED = 'signed';

    public const TYPE_VERSION = 'version_created';

    public const TYPE_SHARE_CREATED = 'share_created';

    public const TYPE_SHARE_REVOKED = 'share_revoked';

    public const TYPE_SHARE_VIEWED = 'share_viewed';

    public const TYPE_SHARE_DOWNLOADED = 'share_downloaded';

    /** The technician's "arrived" / "left" taps on site. */
    public const TYPE_ARRIVED = 'arrived';

    public const TYPE_DEPARTED = 'departed';

    /** The customer signed: the results were locked (with their hash). */
    public const TYPE_LOCKED = 'locked';

    /** A locked work order was replaced by a revision (on both: the old one and the new one). */
    public const TYPE_REVISED = 'revised';

    public const TYPE_SIGN_LINK_CREATED = 'sign_link_created';

    public const TYPE_SIGN_LINK_REVOKED = 'sign_link_revoked';

    protected $fillable = ['pm_visit_id', 'type', 'from_status', 'to_status', 'note', 'data', 'user_id', 'user_name', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PmVisit::class, 'pm_visit_id');
    }
}
