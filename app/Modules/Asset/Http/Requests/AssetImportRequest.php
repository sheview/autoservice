<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('import', Asset::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            // The rows' customer (unless a row names one) and the MA contract every row goes into (StartAssetImport).
            'customer_id' => ['nullable', 'required_with:contract_id', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'contract_id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file' => __('asset.imports.file'),
            'customer_id' => __('asset.imports.customer'),
            'contract_id' => __('asset.imports.contract'),
        ];
    }
}
