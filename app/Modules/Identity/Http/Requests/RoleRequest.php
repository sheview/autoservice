<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            ? $this->user()->can('update', $role)
            : $this->user()->can('create', Role::class);
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [
                Rule::requiredIf($role === null),
                'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/',
                // RLS limits this to the current tenant's roles.
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'label' => ['required', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::tenantPermissions())],
        ];
    }
}
