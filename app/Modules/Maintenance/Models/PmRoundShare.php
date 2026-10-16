<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A link that lets a customer see (and download) a round report without an account: the
 * approved work orders of a customer (or one plan) due from_on..to_on, as they stand when the
 * link is opened. Only the SHA-256 of its token is stored (ShareToken); the link is shown once.
 */
class PmRoundShare extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'customer_id', 'pm_plan_id', 'from_on', 'to_on', 'token_hash', 'recipient_name', 'recipient_email', 'allow_download',
        'expires_at', 'revoked_at', 'revoked_by_name', 'view_count', 'last_viewed_at', 'created_by', 'created_by_name',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'from_on' => 'date',
            'to_on' => 'date',
            'allow_download' => 'boolean',
            'view_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    /** Not revoked and not expired. */
    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * The round report's filters (PmRoundReport).
     *
     * @return array{customer_id: int, plan_id: int|null, from: string, to: string}
     */
    public function filters(): array
    {
        return [
            'customer_id' => $this->customer_id,
            'plan_id' => $this->pm_plan_id,
            'from' => $this->from_on->toDateString(),
            'to' => $this->to_on->toDateString(),
        ];
    }
}
