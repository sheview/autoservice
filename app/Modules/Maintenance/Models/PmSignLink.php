<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link (QR) for the customer to sign a work order on their own phone. Only the SHA-256 of its
 * token is kept (ShareToken); it lasts until expires_at, works once, and can be revoked.
 */
class PmSignLink extends Model
{
    use BelongsToTenant;

    protected $fillable = ['pm_visit_id', 'token_hash', 'expires_at', 'used_at', 'revoked_at', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PmVisit::class, 'pm_visit_id');
    }

    public function isActive(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
