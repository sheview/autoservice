<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;

/**
 * Parts of the company that the one being typed would repeat: the same part number or the same
 * name, ignoring case, spaces and punctuation ("CF-283A" = "cf283a", "Toner HP 59A" =
 * "toner hp-59a"). Saving such a part is refused (PartRequest); the form shows them while typing
 * so the existing one is used instead.
 */
class FindDuplicateParts
{
    public const LIMIT = 5;

    /** What two values must share to be the same: lower case, without spaces or ASCII punctuation. */
    public static function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/[\s[:punct:]]+/u', '', (string) $value) ?? '');
    }

    /**
     * @return list<array{id: int, code: string, name: string, part_number: string|null, brand: string|null, unit: string,
     *     qty_on_hand: int, is_active: bool, reasons: list<string>}>  reasons: part_number / name
     */
    public function handle(?string $name, ?string $partNumber, ?int $except = null): array
    {
        $checks = array_filter([
            'part_number' => self::normalize($partNumber),
            'name' => self::normalize($name),
        ], fn (string $value) => $value !== '');
        if ($checks === []) {
            return [];
        }

        // The same normalising in SQL (MariaDB REGEXP_REPLACE), so only the matches come back.
        $sql = fn (string $column) => "LOWER(REGEXP_REPLACE(COALESCE({$column}, ''), '[[:space:][:punct:]]', ''))";

        return Part::query()
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where(function ($q) use ($checks, $sql) {
                foreach ($checks as $column => $value) {
                    $q->orWhereRaw($sql($column).' = ?', [$value]);
                }
            })
            ->orderBy('code')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'qty_on_hand', 'is_active'])
            ->map(fn (Part $part) => [
                ...$part->only(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'qty_on_hand', 'is_active']),
                'reasons' => array_values(array_filter(array_keys($checks), fn (string $column) => self::normalize($part->{$column}) === $checks[$column])),
            ])
            ->all();
    }
}
