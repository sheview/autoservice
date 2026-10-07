<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Actions\GuardLastAdmin;
use App\Modules\Identity\Actions\SaveUser;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\CrossTenant\CompanyStaff;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The platform lets a person work in another company, or stops it (page "ช่างหลายบริษัท").
 *
 * On: their linked account there is switched on, or made: same name, employee code, phone and
 * service lines, the role they have in their own company if that company has it (else
 * technician), no branch. Its e-mail is their main e-mail tagged with the company
 * (somchai+datacom@itbtthai.com); it never logs in and its mail goes to the main e-mail.
 * Off: the linked account is deactivated (kept, with its work and history).
 * The company's admins adjust role and branch afterwards as for any of their users.
 */
class SetStaffCompany
{
    public function __construct(
        private CompanyStaff $staff,
        private SaveUser $saveUser,
        private GuardLastAdmin $guardLastAdmin,
        private TenantContext $context,
    ) {}

    public function handle(User $main, Tenant $tenant, bool $on): void
    {
        if ($tenant->is_platform || $tenant->id === $main->tenant_id) {
            throw ValidationException::withMessages(['company' => __('platform.linked_staff.home_company')]);
        }

        $row = $this->staff->linkedIn($main, $tenant->id);

        $this->context->run($tenant, function () use ($main, $tenant, $on, $row) {
            if ($row !== null) {
                if (! $on) {
                    $this->guardLastAdmin->handle($row, null, false);
                }
                $row->update(['is_active' => $on]);
            } elseif ($on) {
                $this->saveUser->handle(null, [
                    'name' => $main->name,
                    'email' => $this->emailFor($main, $tenant),
                    'employee_code' => $main->employee_code,
                    'position' => $main->position,
                    'phone' => $main->phone,
                    'service_lines' => $main->service_lines ?? [],
                    'is_active' => true,
                    'role' => $this->roleFor($main, $tenant),
                    'login_user_id' => $main->id,
                ]);
            }
        });
    }

    /** The role the person has in their own company, if this company has it; otherwise technician. */
    private function roleFor(User $main, Tenant $tenant): string
    {
        $homeRole = $this->context->run($main->tenant_id, fn () => $main->getRoleNames()->first());

        return $homeRole !== null && $homeRole !== PermissionCatalog::CUSTOMER_ROLE && Role::where('name', $homeRole)->exists()
            ? $homeRole
            : 'technician';
    }

    private function emailFor(User $main, Tenant $tenant): string
    {
        [$local, $domain] = explode('@', $main->email, 2);
        $tag = Str::slug($tenant->subdomain) ?: 'company'.$tenant->id;

        for ($n = 1; ; $n++) {
            $email = mb_strtolower($local.'+'.$tag.($n > 1 ? $n : '').'@'.$domain);
            if (! $this->staff->emailTaken($email)) {
                return $email;
            }
        }
    }
}
