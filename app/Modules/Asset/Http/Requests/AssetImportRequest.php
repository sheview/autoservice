<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    public function attributes(): array
    {
        return ['file' => __('asset.imports.file')];
    }
}
