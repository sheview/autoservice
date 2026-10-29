<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Platform\Support\SimilarText;

/**
 * Parts of the company that may be the one being typed, though not the same to the letter (those
 * are FindDuplicateParts, which refuses the save): a name with the same words in another order, a
 * longer or shorter part number, a typo (SimilarText). Only a hint: the form shows them while
 * typing so the existing part is used instead of a second one.
 */
class FindSimilarParts
{
    public const LIMIT = 5;

    /** Candidates looked at before scoring. */
    private const CANDIDATES = 200;

    /**
     * @param  list<int>  $except  the part edited and the exact matches already shown
     * @return list<array{id: int, code: string, name: string, part_number: string|null, brand: string|null, unit: string,
     *     qty_on_hand: int, is_active: bool, reasons: list<string>}>  reasons: similar_name / similar_part_number
     */
    public function handle(?string $name, ?string $partNumber, array $except = []): array
    {
        $words = array_slice(SimilarText::words($name), 0, 3);
        $number = SimilarText::normalize($partNumber);
        if ($words === [] && mb_strlen($number) < 4) {
            return [];
        }

        $sql = "LOWER(REGEXP_REPLACE(COALESCE(part_number, ''), '[[:space:][:punct:]]', ''))";

        return Part::query()
            ->when($except !== [], fn ($q) => $q->whereKeyNot($except))
            ->where(function ($q) use ($words, $number, $sql) {
                foreach ($words as $word) {
                    $q->orWhere('name', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
                if (mb_strlen($number) >= 4) {
                    $q->orWhereRaw("{$sql} like ?", ['%'.addcslashes($number, '%_\\').'%'])
                        ->orWhereRaw("({$sql} <> '' and ? like concat('%', {$sql}, '%'))", [$number]);
                }
            })
            ->limit(self::CANDIDATES)
            ->get(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'qty_on_hand', 'is_active'])
            ->map(function (Part $part) use ($name, $partNumber) {
                $byName = SimilarText::score($name, $part->name);
                $byNumber = SimilarText::score($partNumber, $part->part_number);

                return [
                    ...$part->only(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'qty_on_hand', 'is_active']),
                    'reasons' => array_keys(array_filter(
                        ['similar_name' => $byName, 'similar_part_number' => $byNumber],
                        fn (float $score) => $score >= SimilarText::THRESHOLD,
                    )),
                    'score' => max($byName, $byNumber),
                ];
            })
            ->filter(fn (array $part) => $part['reasons'] !== [])
            ->sortByDesc('score')
            ->take(self::LIMIT)
            ->map(fn (array $part) => collect($part)->except('score')->all())
            ->values()
            ->all();
    }
}
