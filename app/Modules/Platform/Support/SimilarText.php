<?php

namespace App\Modules\Platform\Support;

/**
 * How alike two names or codes are, for "this may be the same as ..." hints on the forms (not for
 * refusing a save: that stays the exact match of each module's FindDuplicate* action). Case,
 * spaces and punctuation do not count; the words may come in another order ("Toner HP 59A" ~
 * "HP 59A toner cartridge"), one may hold the other ("CF283A" ~ "CF283AX"), or differ by a typo.
 */
class SimilarText
{
    /** From this score on, two values are worth pointing out. */
    public const THRESHOLD = 0.75;

    /** Lower case, without spaces or punctuation. */
    public static function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/[\s[:punct:]]+/u', '', (string) $value) ?? '');
    }

    /**
     * The words worth searching by: lower case, two characters or more, longest first.
     *
     * @return list<string>
     */
    public static function words(?string $value): array
    {
        $words = array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $value)) ?: [],
            fn (string $word) => mb_strlen($word) >= 2,
        ));
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_values($words);
    }

    /** 0 (nothing alike) to 1 (the same once normalised). */
    public static function score(?string $a, ?string $b): float
    {
        $x = self::normalize($a);
        $y = self::normalize($b);
        if ($x === '' || $y === '') {
            return 0.0;
        }
        if ($x === $y) {
            return 1.0;
        }

        $scores = [];
        // One holds the other (a longer model code, a word added).
        $shorter = mb_strlen($x) <= mb_strlen($y) ? $x : $y;
        $longer = $shorter === $x ? $y : $x;
        if (mb_strlen($shorter) >= 4 && str_contains($longer, $shorter)) {
            $scores[] = 0.75 + 0.2 * mb_strlen($shorter) / mb_strlen($longer);
        }
        // The same words in another order: shared words over the fewer words.
        $wa = self::words($a);
        $wb = self::words($b);
        $common = count(array_intersect($wa, $wb));
        if ($common >= 2 && $wa !== [] && $wb !== []) {
            $scores[] = $common / min(count($wa), count($wb));
        }
        // A typo: letters in common (similar_text) on the normalised values.
        if (mb_strlen($shorter) >= 5) {
            similar_text($x, $y, $percent);
            $scores[] = $percent / 100;
        }

        return $scores === [] ? 0.0 : min(0.99, max($scores));
    }
}
