<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\CrossTenant\UniqueUserEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            ? $this->user()->can('update', $user)
            : $this->user()->can('create', User::class);
    }

    /**
     * Branch and role rules only see rows of the current tenant (RLS + tenant scope),
     * so an id from another tenant fails "exists".
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            // One e-mail = one login across the whole platform.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', new UniqueUserEmail($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'service_lines' => ['array'],
            'service_lines.*' => [Rule::in(User::SERVICE_LINES)],
            'is_active' => ['boolean'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $user = $this->route('user');
                // Do not let an admin lock themselves out.
                if ($user?->is($this->user()) && $this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', __('identity.users.cannot_deactivate_self'));
                }
            },
        ];
    }
}
