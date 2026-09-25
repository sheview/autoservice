<?php

namespace App\Modules\Platform\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Session\Session;

/**
 * A superadmin working inside another tenant ("view as tenant").
 *
 * The superadmin stays logged in as themselves (so every log shows the real person);
 * only the tenant changes. The target tenant is kept in the session and re-checked on
 * every request by ResolveTenant through tenantFor().
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonation.tenant_id';

    public const PERMISSION = 'platform.impersonate';

    private ?Tenant $tenant = null;

    public function __construct(private TenantContext $context) {}

    public function start(Session $session, Tenant $tenant): void
    {
        $session->put(self::SESSION_KEY, $tenant->id);
    }

    public function stop(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
        $this->tenant = null;
    }

    /**
     * Forget the state of a previous request (the singleton lives across requests in tests/Octane).
     */
    public function reset(): void
    {
        $this->tenant = null;
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

        if ($tenant === null || $tenant->is_platform || ! $this->mayImpersonate($user)) {
            $this->stop($session);

            return null;
        }

        return $this->tenant = $tenant;
    }

    /**
     * Whether the user holds platform.impersonate in their own (home) tenant.
     */
    public function mayImpersonate(User $user): bool
    {
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $allowed = $this->context->run($user->tenant_id, fn () => $user->checkPermissionTo(self::PERMISSION));

        // Roles were loaded for the home tenant; drop them so the current tenant reloads its own.
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $allowed;
    }

    public function active(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }
}
