<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Support\Money;
use App\Modules\Asset\Support\SpecFields;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetRequest extends FormRequest
{
    private ?AssetCategory $category = null;

    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset
            ? $this->user()->can('update', $asset)
            : $this->user()->can('create', Asset::class);
    }

    /**
     * Category, branch and code rules only see rows of the current tenant (RLS + tenant scope),
     * so an id from another tenant fails "exists" and codes are unique per tenant.
     */
    public function rules(): array
    {
        $asset = $this->route('asset');

        return [
            'category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            // Empty = next code of the category. Unique including deleted assets.
            'asset_code' => ['nullable', 'string', 'max:50', Rule::unique('assets', 'asset_code')->ignore($asset?->id)],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(Asset::STATUSES)],
            'location' => ['nullable', 'string', 'max:255'],
            'purchased_at' => ['nullable', 'date'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], // baht
            'warranty_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'specs' => ['array'],
            ...SpecFields::rules($this->category()?->spec_fields ?? []),
        ];
    }

    public function attributes(): array
    {
        return SpecFields::labels($this->category()?->spec_fields ?? []);
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $user = $this->user();
                if ($user->can(PermissionCatalog::ALL_BRANCHES)) {
                    return;
                }

                // Without branch.all an asset can only be put in the user's own branch (or none).
                $branchId = $this->input('branch_id') === null ? null : (int) $this->input('branch_id');
                if (! in_array($branchId, [null, $user->branch_id], true)) {
                    $validator->errors()->add('branch_id', __('asset.assets.branch_not_allowed'));
                }
            },
        ];
    }

    /**
     * The validated data for SaveAsset (purchase_price converted to satang).
     *
     * @return array<string, mixed>
     */
    public function assetData(): array
    {
        $data = $this->validated();
        $data['purchase_price'] = Money::toSatang($data['purchase_price'] ?? null);

        return $data;
    }

    private function category(): ?AssetCategory
    {
        $id = $this->input('category_id');

        return $this->category ??= is_numeric($id) ? AssetCategory::find((int) $id) : null;
    }
}
