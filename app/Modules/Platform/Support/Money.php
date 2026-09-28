<?php

namespace App\Modules\Platform\Support;

/**
 * Money is stored as integer satang. Forms and Excel use baht with up to 2 decimals.
 */
class Money
{
    /**
     * "12,500.5" / 12500.5 / "12500" => 1250050. Parsed as a string so no float rounding creeps in.
     */
    public static function toSatang(string|int|float|null $baht): ?int
    {
        if ($baht === null || $baht === '') {
            return null;
        }

        $text = is_float($baht) ? number_format($baht, 2, '.', '') : str_replace([',', ' '], '', (string) $baht);
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function toBaht(?int $satang): ?string
    {
        return $satang === null ? null : number_format($satang / 100, 2, '.', '');
    }
}
