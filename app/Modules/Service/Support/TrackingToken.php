<?php

namespace App\Modules\Service\Support;

use Illuminate\Support\Str;

/**
 * The secret of a ticket's tracking link: 40 random letters and digits (about 238 bits), not in
 * any order and not the ticket number, so a link cannot be guessed from another.
 */
class TrackingToken
{
    public const LENGTH = 40;

    public static function make(): string
    {
        return Str::random(self::LENGTH);
    }

    /** Whether text could be a token at all (checked before any query). */
    public static function looksValid(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{'.self::LENGTH.'}$/', $token);
    }
}
