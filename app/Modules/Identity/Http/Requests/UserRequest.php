<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\CrossTenant\LinkedAccounts;
use App\Modules\Platform\CrossTenant\UniqueUserEmail;
use App\Modules\Tenancy\Support\TenantContext;
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
            // A row linked to a main account never logs in itself, so it needs no password of its own.
            'password' => [$user || $this->filled('main_email') ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            // A person who also works in another company: the e-mail of their main account there.
            'main_email' => ['nullable', 'string', 'email', 'max:255'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at'),
                // users.manage scope branch: only into the manager's own branch (or none).
                ...(DataScope::of($this->user(), 'users.manage') === PermissionCatalog::SCOPE_BRANCH
                    ? [Rule::in(array_filter([$this->user()->branch_id]))] : [])],
            // Set = a customer account (Contract module customer), which must have the customer role.
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
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
                // Customer accounts and the customer role go together.
                $isCustomerRole = $this->input('role') === PermissionCatalog::CUSTOMER_ROLE;
                if ($this->filled('customer_id') !== $isCustomerRole) {
                    $validator->errors()->add($isCustomerRole ? 'customer_id' : 'role', __($isCustomerRole ? 'identity.users.customer_required' : 'identity.users.customer_role_only'));
                }

                if ($user?->is($this->user()) && $this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', __('identity.users.cannot_deactivate_self'));
                }

                if ($this->filled('main_email') && ! $validator->errors()->has('main_email')) {
                    $this->checkMainAccount($validator, $user);
                }
            },
        ];
    }

    /**
     * The main account the user's row is linked to (null = the row is its own login).
     */
    public function loginUserId(): ?int
    {
        return $this->filled('main_email')
            ? app(LinkedAccounts::class)->linkableMain((string) $this->input('main_email'), app(TenantContext::class)->id())?->id
            : null;
    }

    private function checkMainAccount($validator, ?User $user): void
    {
        $accounts = app(LinkedAccounts::class);
        $main = $accounts->linkableMain((string) $this->input('main_email'), app(TenantContext::class)->id());

        $error = match (true) {
            $main === null => 'identity.users.main_not_found',
            $this->filled('customer_id') => 'identity.users.main_not_for_customer',
            $user !== null && $accounts->hasLinkedAccounts($user) => 'identity.users.main_is_main',
            $user?->is($this->user()) => 'identity.users.main_not_self',
            // One row per company for each person (the tenant scope limits this to the current company).
            User::where('login_user_id', $main->id)->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists() => 'identity.users.main_taken',
            default => null,
        };

        if ($error !== null) {
            $validator->errors()->add('main_email', __($error));
        }
    }
}
