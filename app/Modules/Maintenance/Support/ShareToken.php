<?php

namespace App\Modules\Maintenance\Support;

use Illuminate\Support\Str;

/**
 * The secret of a customer's PM report link. Only its SHA-256 is stored (pm_report_shares.token_hash),
 * so the database alone never gives a working link; the link is shown once, when made.
 */
final class ShareToken
{
    public const LENGTH = 48;

    public static function make(): string
    {
        return Str::random(self::LENGTH);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Shaped like a token: anything else is not looked up at all. */
    public static function looksValid(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{'.self::LENGTH.'}$/', $token);
    }
}
