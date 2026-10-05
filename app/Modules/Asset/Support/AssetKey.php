<?php

namespace App\Modules\Asset\Support;

/**
 * The key in an asset's QR code: 8 characters from a set without look-alikes (no 0/o, 1/l/i),
 * about 40 bits — enough that guessing one for a known asset code is hopeless under rate limits.
 */
class AssetKey
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public const LENGTH = 8;

    public static function make(): string
    {
        $key = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $key .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $key;
    }

    /** Compares in constant time; false for anything missing. */
    public static function matches(?string $expected, ?string $given): bool
    {
        return $expected !== null && $given !== null && hash_equals($expected, strtolower(trim($given)));
    }
}
