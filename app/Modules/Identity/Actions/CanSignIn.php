<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\CrossTenant\LinkedAccounts;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Whether this account may sign in, here, this way — the one rule for the password form
 * (LoginRequest) and Keycloak (SignInWithSso), so neither is a way around the other.
 *
 *  - active, and a main account: a linked row (login_user_id) never signs in itself, its person
 *    signs in with the main account and switches company;
 *  - on a company's subdomain ($tenantId): only that company's users, or a person whose own row
 *    there is linked to this account;
 *  - the way ($method) is one the company allows (Tenant::auth_mode). Customers' accounts always
 *    use a password (they have no Keycloak account); the platform's own staff may use either, so
 *    the platform can still be reached when Keycloak is down.
 */
class CanSignIn
{
    public const PASSWORD = 'password';

    public const SSO = 'sso';

    public function __construct(private LinkedAccounts $linkedAccounts) {}

    public function handle(User $user, string $method, ?int $tenantId): bool
    {
        if (! $user->is_active || $user->login_user_id !== null) {
            return false;
        }

        if ($tenantId !== null && $user->tenant_id !== $tenantId && $this->linkedAccounts->accountIn($user, $tenantId) === null) {
            return false;
        }

        $tenant = $user->tenant;
        $methods = match (true) {
            $user->customer_id !== null => [self::PASSWORD],
            (bool) $tenant?->is_platform => [self::PASSWORD, self::SSO],
            default => match ($tenant?->auth_mode) {
                Tenant::AUTH_BOTH => [self::PASSWORD, self::SSO],
                Tenant::AUTH_SSO_ONLY => [self::SSO],
                default => [self::PASSWORD],
            },
        };

        return in_array($method, $methods, true);
    }
}
