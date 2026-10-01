<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Asset\Models\AssetSerial;
use App\Modules\Asset\Support\SpecFields;
use App\Modules\Document\Support\Attachments;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssetRequest extends FormRequest
{
    /** At most this many serial numbers on one asset. */
    public const MAX_SERIALS = 500;

    public const MAX_QUANTITY = 1000000;

    /** Who owns the asset: the company itself, or a customer (customer_id). */
    public const OWNERS = ['company', 'customer'];

    /**
     * Statuses offered when creating only: the asset is already out with someone. It is saved in use,
     * with an approved issue/loan form for that person (RecordCheckout).
     */
    public const HANDED_OUT = ['issued' => AssetCheckout::TYPE_ISSUE, 'loaned' => AssetCheckout::TYPE_LOAN];

    private ?AssetCategory $category = null;

    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset
            ? $this->user()->can('update', $asset)
            : $this->user()->can('create', Asset::class);
    }

    /**
     * Serial numbers are trimmed and blank rows dropped; a category that requires serials counts
     * its quantity from them (otherwise, when not sent, it is the number of serials or 1); an asset of the company has no customer.
     */
    protected function prepareForValidation(): void
    {
        $serials = $this->input('serials', []);
        if (is_array($serials)) {
            $serials = array_values(array_filter(
                array_map(fn ($serial) => is_scalar($serial) ? trim((string) $serial) : $serial, $serials),
                fn ($serial) => $serial !== '' && $serial !== null,
            ));
        }

        // MAC written as aa-bb-cc-dd-ee-ff, aabb.ccdd.eeff or aabbccddeeff is kept as AA:BB:CC:DD:EE:FF.
        $mac = $this->input('mac_address');
        if (is_string($mac) && preg_match('/^[0-9a-f]{12}$/i', $hex = preg_replace('/[^0-9a-f]/i', '', $mac))) {
            $mac = strtoupper(implode(':', str_split($hex, 2)));
        }

        $this->merge([
            'serials' => $serials,
            'mac_address' => $mac,
            ...($this->input('owner') === 'company' ? ['customer_id' => null] : []),
            ...($this->has('quantity') ? [] : ['quantity' => max(is_array($serials) ? count($serials) : 0, 1)]),
            ...($this->category()?->requires_serial && is_array($serials) ? ['quantity' => max(count($serials), 1)] : []),
        ]);
    }

    /**
     * Category, branch and code rules only see rows of the current tenant (RLS + tenant scope),
     * so an id from another tenant fails "exists" and codes are unique per tenant.
     */
    public function rules(): array
    {
        $asset = $this->route('asset');
        $statuses = $asset ? Asset::STATUSES : [...Asset::STATUSES, ...array_keys(self::HANDED_OUT)];
        $handedOut = 'in:'.implode(',', array_keys(self::HANDED_OUT));

        return [
            'category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            // A branch or a location, at least one (checked in after()).
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'location' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'mac_address' => ['nullable', 'regex:/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/'],
            'used_by' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'owner' => ['required', Rule::in(self::OWNERS)],
            'customer_id' => ['nullable', 'required_if:owner,customer', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            // Empty = next code of the category. Unique including deleted assets.
            'asset_code' => ['nullable', 'string', 'max:50', Rule::unique('assets', 'asset_code')->ignore($asset?->id)],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'subtype' => ['nullable', 'string', 'max:100'],
            // Unique per tenant among the serials in use (checked in after(), which names the asset that has it).
            'serials' => [Rule::requiredIf(fn () => (bool) $this->category()?->requires_serial), 'array', 'max:'.self::MAX_SERIALS],
            'serials.*' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'unit' => ['nullable', 'string', 'max:30'],
            // Unique including deleted assets, like asset_code (the database index says the same).
            'property_no' => ['nullable', 'string', 'max:100', Rule::unique('assets', 'property_no')->ignore($asset?->id)],
            'status' => ['required', Rule::in($statuses)],
            'holder_name' => ['nullable', "required_if:status,{$handedOut}", 'string', 'max:255'],
            'handed_out_on' => ['nullable', "required_if:status,{$handedOut}", 'date', 'before_or_equal:today'],
            'purchased_at' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], // baht
            'warranty_expires_at' => ['nullable', 'required_with:purchased_at', 'date', 'after_or_equal:purchased_at'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'specs' => ['array'],
            ...SpecFields::rules($this->category()?->spec_fields ?? []),
            // Files attached when saving (more can be added on the asset page).
            ...Attachments::rules(),
        ];
    }

    /** @return list<UploadedFile> */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }

    public function attributes(): array
    {
        return [
            ...collect(__('asset.columns'))->only([
                'name', 'brand', 'model', 'subtype', 'quantity', 'unit', 'property_no', 'status', 'location', 'ip_address', 'mac_address',
                'used_by', 'department', 'purchased_at',
                'purchase_price', 'warranty_expires_at', 'notes',
            ])->all(),
            'category_id' => __('asset.columns.category'),
            'branch_id' => __('asset.columns.branch'),
            'customer_id' => __('asset.fields.customer'),
            'owner' => __('asset.fields.owner'),
            'asset_code' => __('asset.columns.asset_code'),
            'serials' => __('asset.columns.serial_number'),
            'serials.*' => __('asset.columns.serial_number'),
            'holder_name' => __('asset.fields.holder_name'),
            'handed_out_on' => __('asset.fields.handed_out_on'),
            ...Attachments::attributes(),
            ...SpecFields::labels($this->category()?->spec_fields ?? []),
        ];
    }

    public function messages(): array
    {
        return [
            'serials.required' => __('asset.assets.serial_required', ['category' => $this->category()?->name ?? '']),
            'customer_id.required_if' => __('asset.assets.customer_required'),
            'holder_name.required_if' => __('asset.assets.handed_out_required'),
            'handed_out_on.required_if' => __('asset.assets.handed_out_required'),
            'handed_out_on.before_or_equal' => __('asset.assets.handed_out_future'),
            'warranty_expires_at.required_with' => __('asset.assets.warranty_required'),
            'warranty_expires_at.after_or_equal' => __('asset.assets.warranty_before_purchase'),
            'ip_address.ip' => __('asset.assets.ip_invalid'),
            'mac_address.regex' => __('asset.assets.mac_invalid'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (blank($this->input('branch_id')) && blank($this->input('location'))) {
                    $validator->errors()->add('branch_id', __('asset.assets.place_required'));
                }
            },
            function (Validator $validator) {
                $serials = $this->input('serials');
                if (! is_array($serials) || $this->category()?->requires_serial) {
                    return;
                }
                // Typed-in quantity: never fewer than the serial numbers listed.
                if (is_numeric($this->input('quantity')) && (int) $this->input('quantity') < count($serials)) {
                    $validator->errors()->add('quantity', __('asset.assets.quantity_below_serials', ['count' => count($serials)]));
                }
            },
            fn (Validator $validator) => $this->checkSerialsTaken($validator),
            function (Validator $validator) {
                $user = $this->user();
                $permission = $this->route('asset') instanceof Asset ? 'assets.update' : 'assets.create';
                if (DataScope::of($user, $permission) === PermissionCatalog::SCOPE_ALL) {
                    return;
                }

                // Without reach over the whole company an asset can only be put in the user's own branch (or none).
                $branchId = $this->input('branch_id') === null ? null : (int) $this->input('branch_id');
                if (! in_array($branchId, [null, $user->branch_id], true)) {
                    $validator->errors()->add('branch_id', __('asset.assets.branch_not_allowed'));
                }
            },
        ];
    }

    /**
     * The validated data for SaveAsset (purchase_price converted to satang), without the serials,
     * the hand-over and files.
     *
     * @return array<string, mixed>
     */
    public function assetData(): array
    {
        $data = collect($this->validated())
            ->except(['serials', 'attachments', 'owner', 'holder_name', 'handed_out_on'])
            ->all();
        $data['purchase_price'] = Money::toSatang($data['purchase_price'] ?? null);

        if (isset(self::HANDED_OUT[$data['status']])) {
            $data['status'] = Asset::STATUS_IN_USE;
        }

        return $data;
    }

    /** @return list<string> */
    public function serials(): array
    {
        return array_values($this->validated('serials') ?? []);
    }

    /**
     * The person who has the asset already (status "issued" or "loaned"), or null.
     *
     * @return array{type: string, borrower_name: string, on: string}|null
     */
    public function handedOut(): ?array
    {
        $type = self::HANDED_OUT[$this->validated('status')] ?? null;

        return $type === null ? null : [
            'type' => $type,
            'borrower_name' => $this->validated('holder_name'),
            'on' => $this->validated('handed_out_on'),
        ];
    }

    /**
     * A serial number may be on one asset only: say which asset already has it.
     */
    private function checkSerialsTaken(Validator $validator): void
    {
        $serials = $this->input('serials');
        if (! is_array($serials) || $serials === [] || $validator->errors()->has('serials.*')) {
            return;
        }

        $asset = $this->route('asset');
        $lower = array_map(fn ($serial) => mb_strtolower((string) $serial), $serials);

        $taken = AssetSerial::query()
            ->with('asset:id,asset_code')
            ->whereIn(DB::raw('lower(serial_number)'), $lower)
            ->when($asset instanceof Asset, fn ($q) => $q->where('asset_id', '!=', $asset->id))
            ->get()
            ->keyBy(fn (AssetSerial $serial) => mb_strtolower($serial->serial_number));

        foreach ($lower as $index => $serial) {
            if ($taken->has($serial)) {
                $validator->errors()->add("serials.{$index}", __('asset.assets.serial_taken', [
                    'serial' => $serials[$index],
                    'code' => $taken[$serial]->asset?->asset_code ?? '-',
                ]));
            }
        }
    }

    private function category(): ?AssetCategory
    {
        $id = $this->input('category_id');

        return $this->category ??= is_numeric($id) ? AssetCategory::find((int) $id) : null;
    }
}
