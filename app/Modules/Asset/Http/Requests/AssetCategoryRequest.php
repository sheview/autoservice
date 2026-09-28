<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Models\AssetCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetCategoryRequest extends FormRequest
{
    public const MAX_SPEC_FIELDS = 30;

    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof AssetCategory
            ? $this->user()->can('update', $category)
            : $this->user()->can('create', AssetCategory::class);
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($category) {
                // Unique per tenant, ignoring case (the tenant scope limits the query to this tenant).
                $taken = AssetCategory::query()
                    ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($category, fn ($q) => $q->whereKeyNot($category->id))
                    ->exists();
                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => __('asset.columns.category')]));
                }
            }],
            'code_prefix' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            'service_line' => ['nullable', Rule::in(AssetCategory::SERVICE_LINES)],
            'spec_fields' => ['array', 'max:'.self::MAX_SPEC_FIELDS],
            'spec_fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'spec_fields.*.label' => ['required', 'string', 'max:100'],
            'spec_fields.*.type' => ['required', Rule::in(AssetCategory::FIELD_TYPES)],
            'spec_fields.*.options' => ['array', 'required_if:spec_fields.*.type,select'],
            'spec_fields.*.options.*' => ['nullable', 'string', 'max:100'],
            'spec_fields.*.required' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('asset.categories.fields.name'),
            'code_prefix' => __('asset.categories.fields.code_prefix'),
            'spec_fields.*.key' => __('asset.categories.fields.spec_key'),
            'spec_fields.*.label' => __('asset.categories.fields.spec_label'),
            'spec_fields.*.type' => __('asset.categories.fields.spec_type'),
            'spec_fields.*.options' => __('asset.categories.fields.spec_options'),
        ];
    }
}
