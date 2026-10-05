<?php

namespace App\Modules\RoomAccess\Support;

/**
 * ID card numbers of entrants as shown by default: most digits hidden. A Thai citizen ID (13
 * digits) reads "1-23XX-XXXXX-XX-1"; anything else keeps its first two and last character.
 */
class IdNumber
{
    public static function mask(?string $number): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $number);

        if (strlen($digits) === 13) {
            return sprintf('%s-%sXX-XXXXX-XX-%s', $digits[0], substr($digits, 1, 2), $digits[12]);
        }

        $plain = trim($number);
        $length = mb_strlen($plain);

        return $length <= 3 ? str_repeat('X', $length) : mb_substr($plain, 0, 2).str_repeat('X', $length - 3).mb_substr($plain, -1);
    }

    /** The number as kept: digits only for a 13-digit Thai ID, else as typed (trimmed). */
    public static function clean(?string $number): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $number);

        return strlen($digits) === 13 ? $digits : trim($number);
    }
}
