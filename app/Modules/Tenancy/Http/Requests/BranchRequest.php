<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch
            ? $this->user()->can('update', $branch)
            : $this->user()->can('create', Branch::class);
    }

    public function rules(): array
    {
        $branch = $this->route('branch');

        return [
            // Unique among the live branches of this tenant (TenantPresenceVerifier limits it to the tenant).
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('branches', 'code')->whereNull('deleted_at')->ignore($branch?->id)],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'province' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return __('tenancy.branches.fields');
    }
}
