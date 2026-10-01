<?php

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\CrossTenant\UniqueUserEmail;
use App\Modules\Platform\Http\Controllers\TenantModuleController;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * A customer company as the platform sells it: name, address (subdomain), status and the paid
 * period. Creating one also creates its first admin account.
 */
class TenantRequest extends FormRequest
{
    /** Subdomains the platform keeps for itself. */
    public const RESERVED = ['admin', 'www', 'app', 'api', 'mail', 'platform'];

    public function authorize(): bool
    {
        // From the platform tenant itself, never while working inside a company.
        return ! app(Impersonation::class)->active()
            && $this->user()->checkPermissionTo(TenantModuleController::PERMISSION);
    }

    public function rules(): array
    {
        $tenant = $this->route('tenant');
        $creating = ! $tenant instanceof Tenant;

        return [
            'name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,28}[a-z0-9])?$/',
                Rule::unique('tenants', 'subdomain')->ignore($tenant?->id),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (in_array($value, self::RESERVED, true)) {
                        $fail(__('platform.tenants.subdomain_reserved'));
                    }
                },
            ],
            'status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
            'subscription_starts_on' => ['nullable', 'date'],
            'subscription_ends_on' => ['nullable', 'date', 'after_or_equal:subscription_starts_on'],
            'admin_name' => [$creating ? 'required' : 'prohibited', 'string', 'max:255'],
            'admin_email' => [$creating ? 'required' : 'prohibited', 'string', 'lowercase', 'email', 'max:255', new UniqueUserEmail],
            'admin_password' => [$creating ? 'required' : 'prohibited', 'confirmed', Password::defaults()],
        ];
    }

    public function attributes(): array
    {
        return __('platform.fields');
    }
}
