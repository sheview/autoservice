<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A link to one request that works without signing in, for one purpose, until it expires or is
 * revoked: the permit shown at the guard's counter, the guard's enter / leave link (step 5), and
 * later the customer's approver and entrants accepting the rules themselves.
 */
class RoomAccessToken extends Model
{
    use BelongsToTenant;

    public const PERMIT = 'permit';

    public const GUARD = 'guard';

    public const LENGTH = 40;

    /** A permit link works until this long after the planned end. */
    public const GRACE_HOURS = 12;

    protected $fillable = ['request_id', 'purpose', 'token', 'person_id', 'holder_name', 'expires_at', 'revoked_at', 'revoked_by_name', 'last_used_at', 'created_by_name'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public static function newToken(): string
    {
        return Str::random(self::LENGTH);
    }

    /** Only a string of the right shape is looked up (the same work for any other). */
    public static function looksValid(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{'.self::LENGTH.'}$/', $token);
    }

    public function usable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RoomAccessRequest::class, 'request_id')->withTrashed();
    }
}
