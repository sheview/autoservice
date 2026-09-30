<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\PartSheet;
use App\Modules\Platform\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Imports an uploaded part Excel file (PartSheet layout) row by row, in the current tenant.
 *
 * A row whose code already exists updates that part; any other row creates a new part.
 * "qty_on_hand" is only read for a new part, where it is received as the opening stock: the
 * stock of an existing part changes through movements, never through a file.
 * A bad row is skipped and reported with its Excel row number; the other rows are still imported.
 * Runs in the request (part lists are small), unlike the queued asset import.
 */
class ImportParts
{
    public const MAX_ROWS = 2000;

    /** At most this many row errors are reported. */
    public const MAX_ERRORS = 200;

    public function __construct(
        private SavePart $savePart,
        private RecordStockMovement $recordMovement,
    ) {}

    /**
     * @return array{ok: bool, total: int, created: int, updated: int, failed: int,
     *     errors: list<array{row: int|null, messages: list<string>}>}
     */
    public function handle(UploadedFile $file, User $actor): array
    {
        try {
            $rows = Excel::toCollection(null, $file)->first() ?? collect();
        } catch (Throwable $e) {
            report($e);

            return $this->failed(__('inventory.imports.unreadable'));
        }

        $columns = PartSheet::mapHeadings($rows->first()?->all() ?? []);
        if (! in_array('code', $columns, true) || ! in_array('name', $columns, true)) {
            return $this->failed(__('inventory.imports.missing_headings'));
        }

        $dataRows = $rows->slice(1)->filter(fn (Collection $row) => $row->contains(fn ($cell) => $cell !== null && $cell !== ''));
        if ($dataRows->count() > self::MAX_ROWS) {
            return $this->failed(__('inventory.imports.too_many_rows', ['max' => self::MAX_ROWS]));
        }

        $result = ['ok' => true, 'total' => $dataRows->count(), 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];

        foreach ($dataRows as $index => $row) {
            $values = [];
            foreach ($columns as $cell => $column) {
                $value = $row[$cell] ?? null;
                // A text column may come back as a number (e.g. code 12345); numbers stay as read.
                $isText = ! in_array($column, ['min_qty', 'unit_cost', 'qty_on_hand'], true);
                $values[$column] = match (true) {
                    is_string($value) => trim($value) === '' ? null : trim($value),
                    $isText && is_scalar($value) => (string) $value,
                    default => $value,
                };
            }

            $outcome = $this->importRow($values, $actor);

            if (is_array($outcome)) {
                $result['failed']++;
                if (count($result['errors']) < self::MAX_ERRORS) {
                    // Excel rows start at 1; $index 0 is the heading row.
                    $result['errors'][] = ['row' => $index + 1, 'messages' => $outcome];
                }
            } else {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $values  column => cell (only the columns of the file)
     * @return 'created'|'updated'|list<string> the counter to bump, or the error messages
     */
    private function importRow(array $values, User $actor): string|array
    {
        $code = (string) ($values['code'] ?? '');
        $existing = $code === '' ? null : Part::withTrashed()->whereRaw('lower(code) = ?', [mb_strtolower($code)])->first();
        if ($existing?->trashed()) {
            return [__('inventory.imports.code_deleted', ['code' => $code])];
        }

        // A column that is not in the file leaves the part as it is.
        $data = ['code' => $code === '' ? null : $code, 'name' => $values['name'] ?? null];
        foreach (['brand', 'part_number', 'notes'] as $column) {
            $data[$column] = array_key_exists($column, $values) ? $values[$column] : $existing?->{$column};
        }
        $data['unit'] = $values['unit'] ?? $existing?->unit;
        $data['min_qty'] = $values['min_qty'] ?? $existing?->min_qty ?? 0;
        $data['unit_cost'] = array_key_exists('unit_cost', $values)
            ? (is_string($values['unit_cost']) ? str_replace(',', '', $values['unit_cost']) : $values['unit_cost'])
            : Money::toBaht($existing?->unit_cost);
        $opening = $existing ? null : ($values['qty_on_hand'] ?? null);

        $statusText = $values['status'] ?? null;
        $status = $statusText === null ? null : PartSheet::status($statusText);
        $data['is_active'] = $status === null ? ($existing?->is_active ?? true) : $status === 'active';

        $validator = Validator::make($data + ['qty_on_hand' => $opening], [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'part_number' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:30'],
            'min_qty' => ['integer', 'min:0', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'qty_on_hand' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], array_combine(PartSheet::COLUMNS, PartSheet::headings()));

        $messages = $validator->errors()->all();
        if ($statusText !== null && $status === null) {
            $messages[] = __('inventory.imports.unknown_status', ['value' => $statusText]);
        }
        if ($messages !== []) {
            return $messages;
        }

        $data['min_qty'] = (int) $data['min_qty'];
        $data['unit_cost'] = Money::toSatang($data['unit_cost']);

        // The part and its opening stock are saved together or not at all.
        DB::transaction(function () use ($existing, $data, $opening, $actor) {
            $part = $this->savePart->handle($existing, $data);
            if ((int) $opening > 0) {
                $this->recordMovement->handle($part, StockMovement::TYPE_RECEIVE, (int) $opening, $actor, [
                    'unit_cost' => $data['unit_cost'],
                    'reference' => __('inventory.imports.opening_reference'),
                ]);
            }
        });

        return $existing ? 'updated' : 'created';
    }

    /**
     * @return array{ok: bool, total: int, created: int, updated: int, failed: int,
     *     errors: list<array{row: int|null, messages: list<string>}>}
     */
    private function failed(string $message): array
    {
        return ['ok' => false, 'total' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [['row' => null, 'messages' => [$message]]]];
    }
}
