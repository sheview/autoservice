<?php

namespace App\Modules\Inventory\Support;

/**
 * Column layout of the part Excel file, shared by the export, the template and the import,
 * so an exported file can be edited and imported back.
 *
 * Headings are the Thai labels of lang/th/inventory.php "columns"; the import also accepts the
 * column keys.
 */
class PartSheet
{
    public const COLUMNS = ['code', 'name', 'brand', 'part_number', 'unit', 'min_qty', 'unit_cost', 'qty_on_hand', 'status', 'notes'];

    public const STATUSES = ['active', 'inactive'];

    /**
     * @return list<string>
     */
    public static function headings(): array
    {
        return array_map(fn ($column) => __("inventory.columns.{$column}"), self::COLUMNS);
    }

    /**
     * Map a heading row to column index => column. Unknown headings are skipped.
     *
     * @param  array<int, mixed>  $headingRow
     * @return array<int, string>
     */
    public static function mapHeadings(array $headingRow): array
    {
        $byLabel = [];
        foreach (self::COLUMNS as $column) {
            $byLabel[mb_strtolower(__("inventory.columns.{$column}"))] = $column;
            $byLabel[$column] = $column;
        }

        $map = [];
        foreach ($headingRow as $index => $heading) {
            $heading = mb_strtolower(trim((string) $heading));
            if (isset($byLabel[$heading])) {
                $map[$index] = $byLabel[$heading];
            }
        }

        return $map;
    }

    /**
     * A status cell: the key or its Thai label. Returns null when it is neither.
     */
    public static function status(mixed $value): ?string
    {
        $text = mb_strtolower(trim((string) $value));
        foreach (self::STATUSES as $status) {
            if ($text === $status || $text === mb_strtolower(__("inventory.statuses.{$status}"))) {
                return $status;
            }
        }

        return null;
    }
}
