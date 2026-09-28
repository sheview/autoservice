<?php

namespace App\Modules\Asset\Support;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Column layout of the asset Excel file, shared by the export, the template and the import,
 * so an exported file can be edited and imported back.
 *
 * Headings are the Thai labels of lang/th/asset.php "columns"; the import also accepts the
 * column keys. Spec fields are "{label} [spec.{key}]" and are matched by the key in brackets.
 */
class AssetSheet
{
    public const COLUMNS = [
        'asset_code', 'name', 'category', 'branch', 'brand', 'model', 'serial_number', 'status',
        'location', 'purchased_at', 'purchase_price', 'warranty_expires_at', 'notes',
    ];

    /**
     * @param  array<string, string>  $specFields  key => label
     * @return list<string>
     */
    public static function headings(array $specFields): array
    {
        $headings = array_map(fn ($column) => __("asset.columns.{$column}"), self::COLUMNS);

        foreach ($specFields as $key => $label) {
            $headings[] = "{$label} [spec.{$key}]";
        }

        return $headings;
    }

    /**
     * Map a heading row to column index => "column" or "spec.{key}". Unknown headings are skipped.
     *
     * @param  array<int, mixed>  $headingRow
     * @return array<int, string>
     */
    public static function mapHeadings(array $headingRow): array
    {
        $byLabel = [];
        foreach (self::COLUMNS as $column) {
            $byLabel[mb_strtolower(__("asset.columns.{$column}"))] = $column;
            $byLabel[$column] = $column;
        }

        $map = [];
        foreach ($headingRow as $index => $heading) {
            $heading = trim((string) $heading);

            if (preg_match('/\[?spec\.([a-z][a-z0-9_]*)\]?\s*$/', $heading, $match)) {
                $map[$index] = 'spec.'.$match[1];
            } elseif (isset($byLabel[mb_strtolower($heading)])) {
                $map[$index] = $byLabel[mb_strtolower($heading)];
            }
        }

        return $map;
    }

    /**
     * A date cell: an Excel date number, "2026-09-28", "28/09/2026" or a Buddhist year "28/09/2569".
     * Returns Y-m-d, the original text when it cannot be read (validation then rejects it), or null.
     */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }

            $text = trim((string) $value);
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $text, $m)) {
                [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $text, $m)) {
                [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } else {
                return $text;
            }

            // Convert the year first: 29/02/2567 is a real day (2024) although 2567 is not a leap year.
            $year = $year > 2400 ? $year - 543 : $year;

            return CarbonImmutable::createStrict($year, $month, $day)->toDateString();
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
