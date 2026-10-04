<?php

namespace App\Modules\Asset\Support;

/**
 * An IPv4 range typed by a person, for the free-IP check:
 *   192.168.1.0/24           a subnet (network and broadcast addresses left out for /30 and wider)
 *   192.168.1.10-50          the last part as a range
 *   192.168.1.10-192.168.2.5 two full addresses
 *   192.168.1.25             one address
 * At most MAX addresses, so one request cannot list a /8.
 */
class IpRange
{
    public const MAX = 1024;

    /**
     * @return list<string>|string the addresses in order, or the reason the text was refused
     *                             ("invalid" | "too_large")
     */
    public static function parse(string $text): array|string
    {
        $text = preg_replace('/\s+/', '', $text) ?? '';

        if (preg_match('#^([\d.]+)/(\d{1,2})$#', $text, $m)) {
            $base = self::toInt($m[1]);
            $prefix = (int) $m[2];
            if ($base === null || $prefix > 32) {
                return 'invalid';
            }
            $size = 2 ** (32 - $prefix);
            if ($size > self::MAX) {
                return 'too_large';
            }
            $network = $base & ~($size - 1) & 0xFFFFFFFF;
            [$from, $to] = $size >= 4 ? [$network + 1, $network + $size - 2] : [$network, $network + $size - 1];

            return self::list($from, $to);
        }

        if (preg_match('#^([\d.]+)-([\d.]+)$#', $text, $m)) {
            $from = self::toInt($m[1]);
            // "192.168.1.10-50": the second part replaces the last octet.
            $to = str_contains($m[2], '.') ? self::toInt($m[2]) : (ctype_digit($m[2]) && (int) $m[2] <= 255 && $from !== null
                ? ($from & 0xFFFFFF00) | (int) $m[2]
                : null);
            if ($from === null || $to === null || $to < $from) {
                return 'invalid';
            }
            if ($to - $from + 1 > self::MAX) {
                return 'too_large';
            }

            return self::list($from, $to);
        }

        $one = self::toInt($text);

        return $one === null ? 'invalid' : [long2ip($one)];
    }

    /**
     * A subnet as stored: "192.168.1.77/24" -> cidr "192.168.1.0/24", first = the network address.
     * At most MAX addresses (a /22), so a subnet can always be listed whole.
     *
     * @return array{cidr: string, first: int, prefix: int}|string the subnet, or "invalid" | "too_large"
     */
    public static function subnet(string $text): array|string
    {
        $text = preg_replace('/\s+/', '', $text) ?? '';
        if (! preg_match('#^([\d.]+)/(\d{1,2})$#', $text, $m) || ($base = self::toInt($m[1])) === null || (int) $m[2] > 32) {
            return 'invalid';
        }
        $prefix = (int) $m[2];
        $size = 2 ** (32 - $prefix);
        if ($size > self::MAX) {
            return 'too_large';
        }
        $first = $base & ~($size - 1) & 0xFFFFFFFF;

        return ['cidr' => long2ip($first).'/'.$prefix, 'first' => $first, 'prefix' => $prefix];
    }

    /**
     * The addresses that can be given out in a subnet: without the network and broadcast
     * addresses for /30 and wider.
     *
     * @return array{0: int, 1: int} first and last, as integers
     */
    public static function usable(int $first, int $prefix): array
    {
        $size = 2 ** (32 - $prefix);

        return $size >= 4 ? [$first + 1, $first + $size - 2] : [$first, $first + $size - 1];
    }

    /** The address as an integer, or null when it is not an IPv4 address. */
    public static function toInt(string $ip): ?int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return ip2long($ip);
    }

    /**
     * @return list<string>
     */
    private static function list(int $from, int $to): array
    {
        return array_map(fn (int $n) => long2ip($n), range($from, $to));
    }
}
