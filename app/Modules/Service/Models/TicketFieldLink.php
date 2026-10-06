<?php

namespace App\Modules\Service\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A link to work on one ticket without an account: an outside technician fills in the job
 * ("work"), or the customer signs it off ("sign"). Usable until it expires or is revoked, and while
 * the job is still open.
 */
class TicketFieldLink extends Model
{
    use BelongsToTenant;

    public const MODE_WORK = 'work';

    public const MODE_SIGN = 'sign';

    public const MODES = [self::MODE_WORK, self::MODE_SIGN];

    public const LENGTH = 40;

    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 30;

    protected $fillable = [
        'ticket_id', 'token', 'mode', 'holder_name', 'holder_company', 'holder_phone', 'expires_at', 'revoked_at', 'revoked_by_name',
        'last_used_at', 'submitted_at', 'reviewed_at', 'reviewed_by_name', 'created_by', 'created_by_name',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public static function newToken(): string
    {
        return Str::random(self::LENGTH);
    }

    public static function looksValid(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{'.self::LENGTH.'}$/', $token);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** Works now: not revoked or expired, and the job is still open (not closed or cancelled). */
    public function usable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture()
            && $this->ticket !== null && ! in_array($this->ticket->status, [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED], true);
    }
}
