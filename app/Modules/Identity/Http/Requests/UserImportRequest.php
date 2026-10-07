<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UserImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:2048'],
        ];
    }

    public function attributes(): array
    {
        return ['file' => __('identity.imports.file')];
    }
}
