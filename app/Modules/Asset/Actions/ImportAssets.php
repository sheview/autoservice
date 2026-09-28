<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Asset\Support\AssetSheet;
use App\Modules\Asset\Support\Money;
use App\Modules\Asset\Support\SpecFields;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Imports an uploaded asset Excel file (AssetSheet layout) row by row, in the current tenant.
 *
 * A row whose asset_code already exists updates that asset; any other row creates a new asset
 * (an empty asset_code gets the next code of the category). A bad row is skipped and reported
 * with its Excel row number; the other rows are still imported.
 */
class ImportAssets
{
    public const MAX_ROWS = 5000;

    /** At most this many row errors are kept on the import. */
    public const MAX_ERRORS = 200;

    /** @var Collection<string, AssetCategory> lower(name) => category */
    private Collection $categories;

    /** @var Collection<string, Branch> lower(code) and lower(name) => branch */
    private Collection $branches;

    /** @var array<string, string> status key and lower(Thai label) => status key */
    private array $statuses = [];

    public function __construct(private SaveAsset $saveAsset) {}

    /**
     * @param  bool  $allBranches  whether the uploader may write assets of every branch
     * @param  int|null  $ownBranchId  the uploader's branch (used when $allBranches is false)
     */
    public function handle(AssetImport $import, bool $allBranches, ?int $ownBranchId): void
    {
        $import->update(['status' => AssetImport::STATUS_PROCESSING]);

        try {
            $rows = Excel::toCollection(null, $import->file_path, 'local')->first() ?? collect();
            $this->importRows($import, $rows, $allBranches, $ownBranchId);
        } catch (Throwable $e) {
            report($e);
            $import->update([
                'status' => AssetImport::STATUS_FAILED,
                'errors' => [['row' => null, 'messages' => [__('asset.imports.unreadable')]]],
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * @param  Collection<int, Collection<int, mixed>>  $rows  first row = headings
     */
    private function importRows(AssetImport $import, Collection $rows, bool $allBranches, ?int $ownBranchId): void
    {
        $columns = AssetSheet::mapHeadings($rows->first()?->all() ?? []);
        if (! in_array('name', $columns, true) || ! in_array('category', $columns, true)) {
            $this->fail($import, __('asset.imports.missing_headings'));

            return;
        }

        $dataRows = $rows->slice(1)->filter(fn (Collection $row) => $row->contains(fn ($cell) => $cell !== null && $cell !== ''));
        if ($dataRows->count() > self::MAX_ROWS) {
            $this->fail($import, __('asset.imports.too_many_rows', ['max' => self::MAX_ROWS]));

            return;
        }

        $this->loadLookups();
        $counts = ['created_rows' => 0, 'updated_rows' => 0, 'failed_rows' => 0];
        $errors = [];

        foreach ($dataRows as $index => $row) {
            $rowNumber = $index + 1; // Excel rows start at 1; $index 0 is the heading row.
            $values = [];
            foreach ($columns as $cell => $column) {
                $value = $row[$cell] ?? null;
                // A text column may come back as a number (e.g. serial 12345); dates and prices stay as read.
                $isText = ! in_array($column, ['purchased_at', 'warranty_expires_at', 'purchase_price'], true);
                $values[$column] = match (true) {
                    is_string($value) => trim($value) === '' ? null : trim($value),
                    $isText && is_scalar($value) => (string) $value,
                    default => $value,
                };
            }

            $result = $this->importRow($values, $allBranches, $ownBranchId);

            if (is_array($result)) {
                $counts['failed_rows']++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = ['row' => $rowNumber, 'messages' => $result];
                }
            } else {
                $counts[$result]++;
            }
        }

        $import->update($counts + [
            'status' => AssetImport::STATUS_DONE,
            'total_rows' => $dataRows->count(),
            'errors' => $errors,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values  column => cell
     * @return 'created_rows'|'updated_rows'|list<string> the counter to bump, or the error messages
     */
    private function importRow(array $values, bool $allBranches, ?int $ownBranchId): string|array
    {
        $category = $this->categories->get(mb_strtolower((string) ($values['category'] ?? '')));
        $branchText = mb_strtolower((string) ($values['branch'] ?? ''));
        $branch = $branchText === '' ? null : $this->branches->get($branchText);
        $code = (string) ($values['asset_code'] ?? '');
        $existing = $code === '' ? null : Asset::withTrashed()->where('asset_code', $code)->first();

        $data = [
            'asset_code' => $code === '' ? null : $code,
            'name' => $values['name'] ?? null,
            'category_id' => $category?->id,
            'branch_id' => $branch?->id,
            'brand' => $values['brand'] ?? null,
            'model' => $values['model'] ?? null,
            'serial_number' => $values['serial_number'] ?? null,
            'status' => $this->statuses[mb_strtolower((string) ($values['status'] ?? ''))] ?? ($values['status'] ?? null),
            'location' => $values['location'] ?? null,
            'purchased_at' => AssetSheet::date($values['purchased_at'] ?? null),
            'purchase_price' => is_string($values['purchase_price'] ?? null)
                ? str_replace(',', '', $values['purchase_price'])
                : ($values['purchase_price'] ?? null),
            'warranty_expires_at' => AssetSheet::date($values['warranty_expires_at'] ?? null),
            'notes' => $values['notes'] ?? null,
            'specs' => [],
        ];
        foreach ($values as $column => $value) {
            if (str_starts_with($column, 'spec.')) {
                $data['specs'][substr($column, 5)] = $value;
            }
        }

        if (! $allBranches && $branch === null && $branchText === '') {
            $data['branch_id'] = $ownBranchId;
        }
        $data['status'] ??= $existing?->status ?? Asset::STATUS_IN_USE;

        $validator = Validator::make($data, [
            'asset_code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:'.implode(',', Asset::STATUSES)],
            'location' => ['nullable', 'string', 'max:255'],
            'purchased_at' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'warranty_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            ...SpecFields::rules($category?->spec_fields ?? []),
        ], [], [
            ...array_combine(AssetSheet::COLUMNS, array_map(fn ($c) => __("asset.columns.{$c}"), AssetSheet::COLUMNS)),
            'category_id' => __('asset.columns.category'),
            ...SpecFields::labels($category?->spec_fields ?? []),
        ]);

        $errors = $validator->errors();
        if (($values['category'] ?? null) !== null && $category === null) {
            // Say which category was not found instead of "category is required".
            $errors->forget('category_id');
            $errors->add('category', __('asset.imports.unknown_category', ['value' => $values['category']]));
        }
        $messages = $errors->all();
        if ($branchText !== '' && $branch === null) {
            $messages[] = __('asset.imports.unknown_branch', ['value' => $values['branch']]);
        }
        if (! $allBranches && ! in_array($data['branch_id'], [null, $ownBranchId], true)) {
            $messages[] = __('asset.imports.branch_not_allowed');
        }
        if ($existing?->trashed()) {
            $messages[] = __('asset.imports.code_deleted', ['code' => $code]);
        } elseif ($existing && ! $allBranches && ! in_array($existing->branch_id, [null, $ownBranchId], true)) {
            $messages[] = __('asset.imports.branch_not_allowed');
        }

        if ($messages !== []) {
            return array_values(array_unique($messages));
        }

        $data['purchase_price'] = Money::toSatang($data['purchase_price']);
        $this->saveAsset->handle($existing, $data);

        return $existing ? 'updated_rows' : 'created_rows';
    }

    private function loadLookups(): void
    {
        $this->categories = AssetCategory::all()->keyBy(fn (AssetCategory $c) => mb_strtolower($c->name));

        // A base collection: Eloquent's merge() would merge by primary key and drop the string keys.
        $branches = Branch::all()->toBase();
        $this->branches = $branches->keyBy(fn (Branch $b) => mb_strtolower($b->name))
            ->merge($branches->keyBy(fn (Branch $b) => mb_strtolower($b->code)));

        foreach (Asset::STATUSES as $status) {
            $this->statuses[$status] = $status;
            $this->statuses[mb_strtolower(__("asset.statuses.{$status}"))] = $status;
        }
    }

    private function fail(AssetImport $import, string $message): void
    {
        $import->update([
            'status' => AssetImport::STATUS_FAILED,
            'errors' => [['row' => null, 'messages' => [$message]]],
            'finished_at' => now(),
        ]);
    }
}
