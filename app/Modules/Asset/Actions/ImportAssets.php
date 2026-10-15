<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Events\AssetImportRowSaved;
use App\Modules\Asset\Http\Requests\AssetRequest;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Asset\Models\AssetSerial;
use App\Modules\Asset\Support\AssetSheet;
use App\Modules\Asset\Support\SpecFields;
use App\Modules\Contract\Actions\AddAssetToContracts;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Imports an uploaded asset Excel file (AssetSheet layout) row by row, in the current tenant.
 *
 * A row whose asset_code already exists updates that asset; any other row creates a new asset
 * (an empty asset_code gets the next code of the category). A bad row is skipped and reported
 * with its Excel row number; the other rows are still imported. Contract numbers in the row put
 * the asset into those contracts (never out of others). The parent column ties a part to its
 * asset (by code or serial number, of a row above or already registered).
 *
 * An import asked from another module carries its context (AssetImport::context): a contract every
 * row goes into (its customer by default), and that module's own columns, handed on with each saved
 * row (AssetImportRowSaved) — a listener refusing the row undoes it.
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

    /** @var array<string, int> lower(customer code) and lower(name) => customer id */
    private array $customers = [];

    /** @var array<string, array{id: int, customer_id: int}>|null lower(contract no) => contract rows may put their asset into; null = column ignored */
    private ?array $contracts = null;

    /** @var array<string, mixed> see AssetImport::context */
    private array $context = [];

    public function __construct(
        private SaveAsset $saveAsset,
        private ListCustomers $listCustomers,
        private AddAssetToContracts $addToContracts,
        private Modules $modules,
    ) {}

    /**
     * @param  bool  $allBranches  whether the uploader may write assets of every branch
     * @param  int|null  $ownBranchId  the uploader's branch (used when $allBranches is false)
     * @param  array<string, array{id: int, customer_id: int}>|null  $contracts  see $this->contracts (StartAssetImport)
     */
    public function handle(AssetImport $import, bool $allBranches, ?int $ownBranchId, ?array $contracts = null): void
    {
        $this->contracts = $contracts;
        $this->context = $import->context ?? [];
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
        $columns = AssetSheet::mapHeadings($rows->first()?->all() ?? [], $this->context['columns'] ?? []);
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
                $isText = ! in_array($column, ['purchased_at', 'warranty_expires_at', 'purchase_price', 'quantity'], true);
                $values[$column] = match (true) {
                    is_string($value) => trim($value) === '' ? null : trim($value),
                    $isText && is_scalar($value) => (string) $value,
                    default => $value,
                };
            }

            $result = $this->importRow($import, $values, $allBranches, $ownBranchId);

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
    private function importRow(AssetImport $import, array $values, bool $allBranches, ?int $ownBranchId): string|array
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

        // Columns added later (sub-type, quantity, unit) are only set by a file that has them.
        foreach (['subtype', 'quantity', 'unit', 'ip_address', 'mac_address', 'used_by', 'department', 'floor', 'room'] as $column) {
            if (array_key_exists($column, $values)) {
                $data[$column] = $values[$column];
            }
        }
        $serials = array_key_exists('serial_number', $values) ? AssetSheet::serials($values['serial_number']) : null;

        // Only a file with the equipment number column sets it (an older file leaves it alone).
        if (array_key_exists('property_no', $values)) {
            $data['property_no'] = $values['property_no'];
        }

        // Only a file with the customer column sets the customer (an older file leaves it alone).
        $customerText = mb_strtolower((string) ($values['customer'] ?? ''));
        if (array_key_exists('customer', $values)) {
            $data['customer_id'] = $customerText === '' ? null : ($this->customers[$customerText] ?? null);
        }
        // Into the context's contract: its customer unless the row names one.
        $forcedContract = $this->context['contract_id'] ?? null;
        if ($forcedContract !== null && ($data['customer_id'] ?? null) === null && ($customerText === '' || ! array_key_exists('customer', $values))) {
            $data['customer_id'] = $existing?->customer_id ?? ($this->context['customer_id'] ?? null);
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
            'subtype' => ['nullable', 'string', 'max:100'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.AssetRequest::MAX_QUANTITY],
            'unit' => ['nullable', 'string', 'max:30'],
            'ip_address' => ['nullable', 'ip'],
            'mac_address' => ['nullable', 'string', 'max:17'],
            'used_by' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            // Rows already imported count too, so a number twice in the file fails on its second row.
            'property_no' => ['nullable', 'string', 'max:100', Rule::unique('assets', 'property_no')->ignore($existing?->id)],
            'status' => ['required', 'in:'.implode(',', Asset::STATUSES)],
            'location' => ['nullable', 'string', 'max:255'],
            'purchased_at' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'warranty_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'floor' => ['nullable', 'string', 'max:50'],
            'room' => ['nullable', 'string', 'max:100'],
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
        if ($customerText !== '' && ! isset($this->customers[$customerText])) {
            $messages[] = __('asset.imports.unknown_customer', ['value' => $values['customer']]);
        }
        if (! $allBranches && ! in_array($data['branch_id'], [null, $ownBranchId], true)) {
            $messages[] = __('asset.imports.branch_not_allowed');
        }
        if ($existing?->trashed()) {
            $messages[] = __('asset.imports.code_deleted', ['code' => $code]);
        } elseif ($existing && ! $allBranches && ! in_array($existing->branch_id, [null, $ownBranchId], true)) {
            $messages[] = __('asset.imports.branch_not_allowed');
        }

        foreach ($this->serialsTaken($serials ?? [], $existing) as $serial => $assetCode) {
            $messages[] = __('asset.assets.serial_taken', ['serial' => $serial, 'code' => $assetCode]);
        }

        // Contracts to put it into: only added, never taken out (an empty cell changes nothing).
        $contractIds = [];
        if ($this->contracts !== null) {
            $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : $existing?->customer_id;
            $customerId = $customerId === null ? null : (int) $customerId;
            foreach (AssetSheet::serials($values['contracts'] ?? null) as $number) {
                $contract = $this->contracts[mb_strtolower($number)] ?? null;
                $messages[] = match (true) {
                    $contract === null => __('asset.imports.unknown_contract', ['value' => $number]),
                    $contract['customer_id'] !== $customerId => __('asset.imports.contract_other_customer', ['value' => $number]),
                    default => null,
                };
                $contractIds[] = $contract['id'] ?? null;
            }
            $messages = array_filter($messages);
        }
        if ($forcedContract !== null) {
            $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : $existing?->customer_id;
            if ($customerId !== null && (int) $customerId !== (int) ($this->context['customer_id'] ?? 0)) {
                $messages[] = __('asset.imports.contract_other_customer', ['value' => $this->context['contract_no'] ?? '']);
            }
            $contractIds[] = (int) $forcedContract;
        }

        if (array_key_exists('parent', $values)) {
            $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : $existing?->customer_id;
            [$data['parent_asset_id'], $parentErrors] = $this->parent($values['parent'], $existing, $customerId);
            $messages = [...$messages, ...$parentErrors];
        }

        if ($messages !== []) {
            return array_values(array_unique($messages));
        }

        $data['purchase_price'] = Money::toSatang($data['purchase_price']);
        if (($data['quantity'] ?? null) === null) {
            unset($data['quantity']);
        }
        $extra = [];
        foreach ($values as $column => $value) {
            if (str_starts_with($column, 'extra.')) {
                $extra[substr($column, 6)] = $value;
            }
        }

        try {
            DB::transaction(function () use ($import, $existing, $data, $serials, $contractIds, $extra) {
                $asset = $this->saveAsset->handle($existing, $data, $serials);
                $this->addToContracts->handle($asset->id, array_values(array_unique($contractIds)));
                // The module that asked for the import takes it further (or refuses the row).
                if ($this->context !== []) {
                    event(new AssetImportRowSaved($import, $asset, $extra));
                }
            });
        } catch (ValidationException $e) {
            return array_values(array_unique($e->validator->errors()->all()));
        }

        return $existing ? 'updated_rows' : 'created_rows';
    }

    /**
     * The asset a row is a part of, by code or serial number; empty = none.
     *
     * @return array{0: int|null, 1: list<string>} its id, and what is wrong
     */
    private function parent(mixed $cell, ?Asset $existing, mixed $customerId): array
    {
        $text = trim((string) ($cell ?? ''));
        if ($text === '') {
            return [null, []];
        }

        $parent = Asset::query()->whereRaw('lower(asset_code) = ?', [mb_strtolower($text)])->first()
            ?? AssetSerial::query()->with('asset')->whereRaw('lower(serial_number) = ?', [mb_strtolower($text)])->first()?->asset;

        return match (true) {
            $parent === null => [null, [__('asset.imports.unknown_parent', ['value' => $text])]],
            $existing !== null && $parent->id === $existing->id => [null, [__('asset.imports.parent_self')]],
            $parent->parent_asset_id !== null => [null, [__('asset.imports.parent_is_part', ['value' => $text])]],
            $existing !== null && $existing->children()->exists() => [null, [__('asset.imports.has_parts')]],
            $parent->customer_id !== null && $customerId !== null && (int) $parent->customer_id !== (int) $customerId => [null, [__('asset.imports.parent_other_customer', ['value' => $text])]],
            default => [$parent->id, []],
        };
    }

    /**
     * Serial numbers already on another asset (rows imported before count too).
     *
     * @param  list<string>  $serials
     * @return array<string, string> serial as written in the file => asset code
     */
    private function serialsTaken(array $serials, ?Asset $existing): array
    {
        if ($serials === []) {
            return [];
        }

        $written = collect($serials)->keyBy(fn (string $serial) => mb_strtolower($serial));

        return AssetSerial::query()
            ->with('asset:id,asset_code')
            ->whereIn(DB::raw('lower(serial_number)'), $written->keys()->all())
            ->when($existing, fn ($q) => $q->where('asset_id', '!=', $existing->id))
            ->get()
            ->mapWithKeys(fn (AssetSerial $serial) => [
                $written[mb_strtolower($serial->serial_number)] => $serial->asset?->asset_code ?? '-',
            ])
            ->all();
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

        $customers = $this->modules->enabled('contract') ? $this->listCustomers->handle() : [];
        foreach ($customers as $customer) {
            $this->customers[mb_strtolower($customer['name'])] = $customer['id'];
            $this->customers[mb_strtolower($customer['code'])] = $customer['id'];
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
