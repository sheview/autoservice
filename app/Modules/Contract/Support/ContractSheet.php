<?php

namespace App\Modules\Contract\Support;

use App\Modules\Contract\Models\Contract;

/**
 * Column layout of the contract Excel file, shared by the export, the template and the import,
 * so an exported file can be edited and imported back.
 *
 * Headings are the Thai labels of lang/th/contract.php "columns" (SLA columns: "sla_response" /
 * "sla_resolve" with the priority); the import also accepts the column keys, e.g. "sla_critical_response".
 * The customer is given by its code; SLA times are in hours.
 */
class ContractSheet
{
    public const BASE_COLUMNS = [
        'contract_no', 'customer_code', 'title', 'status', 'starts_on', 'ends_on', 'value',
        'service_window', 'pm_interval_months', 'notify_days_before', 'notes',
    ];

    /** Columns read as numbers; the others are text. */
    public const NUMERIC_COLUMNS = ['value', 'pm_interval_months', 'notify_days_before'];

    /**
     * Every column: the base ones, then response / resolve hours per priority.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        $columns = self::BASE_COLUMNS;
        foreach (Contract::PRIORITIES as $priority) {
            $columns[] = self::slaColumn($priority, 'response');
            $columns[] = self::slaColumn($priority, 'resolve');
        }

        return $columns;
    }

    /**
     * @param  'response'|'resolve'  $kind
     */
    public static function slaColumn(string $priority, string $kind): string
    {
        return "sla_{$priority}_{$kind}";
    }

    public static function heading(string $column): string
    {
        if (preg_match('/^sla_([a-z]+)_(response|resolve)$/', $column, $m)) {
            return __("contract.columns.sla_{$m[2]}", ['priority' => __("contract.priorities.{$m[1]}")]);
        }

        return __("contract.columns.{$column}");
    }

    /**
     * @return list<string>
     */
    public static function headings(): array
    {
        return array_map(self::heading(...), self::columns());
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
        foreach (self::columns() as $column) {
            $byLabel[mb_strtolower(self::heading($column))] = $column;
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
        foreach (Contract::STATUSES as $status) {
            if ($text === $status || $text === mb_strtolower(__("contract.statuses.{$status}"))) {
                return $status;
            }
        }

        return null;
    }

    /**
     * A service window cell ("24x7", "24X7", "24 x 7"). Returns null when it is not one.
     */
    public static function serviceWindow(mixed $value): ?string
    {
        $text = str_replace(' ', '', mb_strtolower(trim((string) $value)));

        return in_array($text, Contract::SERVICE_WINDOWS, true) ? $text : null;
    }
}
