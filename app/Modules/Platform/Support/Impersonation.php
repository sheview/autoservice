<?php

namespace App\Modules\Platform\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Session\Session;

/**
 * A platform user working inside a customer tenant ("view as tenant").
 *
 * The user stays logged in as themselves (so every log shows the real person); only the tenant
 * changes. The target tenant is kept in the session and re-checked on every request by
 * ResolveTenant through tenantFor().
 *
 * What they may do there comes from their role in the platform tenant:
 *   - platform.full_access (superadmin): everything, policies are not even asked
 *   - otherwise (central helpdesk, central technician): the tenant permissions their platform
 *     role holds, checked by the normal policies. They hold no settings permissions, so they
 *     see and work on the company's data but cannot configure it.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonation.tenant_id';

    public const PERMISSION = 'platform.impersonate';

    public const FULL_ACCESS = 'platform.full_access';

    private ?Tenant $tenant = null;

    private ?int $userId = null;

    private bool $fullAccess = false;

    /** @var list<string> tenant permissions the user holds while inside the tenant */
    private array $permissions = [];

    public function __construct(private TenantContext $context) {}

    public function start(Session $session, Tenant $tenant): void
    {
        $session->put(self::SESSION_KEY, $tenant->id);
    }

    public function stop(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
        $this->reset();
    }

    /**
     * Forget the state of a previous request (the singleton lives across requests in tests/Octane).
     */
    public function reset(): void
    {
        $this->tenant = null;
        $this->userId = null;
        $this->fullAccess = false;
        $this->permissions = [];
    }

    /**
     * The tenant this user is impersonating on this request, or null.
     * Stops the impersonation if the user is no longer allowed to do it.
     */
    public function tenantFor(User $user, Session $session): ?Tenant
    {
        $tenantId = $session->get(self::SESSION_KEY);
        if ($tenantId === null) {
            return null;
        }

        $tenant = Tenant::find($tenantId);
        $home = $this->homePermissions($user);

        if ($tenant === null || $tenant->is_platform || ! in_array(self::PERMISSION, $home, true)) {
            $this->stop($session);

            return null;
        }

        $this->userId = $user->id;
        $this->fullAccess = in_array(self::FULL_ACCESS, $home, true);
        $this->permissions = $this->fullAccess
            ? PermissionCatalog::tenantPermissions()
            : array_values(array_intersect(PermissionCatalog::tenantPermissions(), $home));

        return $this->tenant = $tenant;
    }

    /**
     * Whether the user holds platform.impersonate in their own (home) tenant.
     */
    public function mayImpersonate(User $user): bool
    {
        return in_array(self::PERMISSION, $this->homePermissions($user), true);
    }

    public function active(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Whether $user is the one working inside another tenant on this request.
     */
    public function actingAs(User $user): bool
    {
        return $this->tenant !== null && $this->userId === $user->id;
    }

    /**
     * Passes every check inside the tenant (superadmin).
     */
    public function fullAccess(): bool
    {
        return $this->tenant !== null && $this->fullAccess;
    }

    /**
     * Whether the impersonating user holds a tenant permission inside the tenant.
     */
    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @return list<string> the tenant permissions of the impersonating user
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * The permission names the user holds through their roles in their own (home) tenant.
     *
     * @return list<string>
     */
    private function homePermissions(User $user): array
    {
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $names = $this->context->run($user->tenant_id, fn () => $user->getAllPermissions()->pluck('name')->all());

        // Roles were loaded for the home tenant; drop them so the current tenant reloads its own.
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $names;
    }
}
