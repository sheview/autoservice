<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SsoUser;

/**
 * The Governix account of a person Keycloak has just signed in, or a Thai reason why not.
 *
 * Found by their Keycloak id ("sub"). The first time, by e-mail — only one Keycloak says it has
 * verified — and the id is kept on the account, so an e-mail changed in Keycloak later can never
 * lead to someone else's account. Nobody is created here: accounts are made in Governix.
 *
 * Behind Keycloak, a company may sign in with its own system (AD/Entra, by a Keycloak
 * Organization): only a sign-in through the account's own company's Organization is believed.
 */
class SignInWithSso
{
    public function __construct(private CanSignIn $canSignIn, private TenantContext $tenants) {}

    /**
     * @throws ValidationException
     */
    public function handle(SsoUser $sso, ?int $tenantId): User
    {
        $sub = (string) $sso->getId();
        if ($sub === '') {
            $this->refuse('auth.sso_failed');
        }

        // The users of every company, before the tenant is known (TenantAwareUserProvider).
        $users = Auth::guard('web')->getProvider();

        /** @var User|null $user */
        $user = $users->retrieveByCredentials(['keycloak_sub' => $sub]);
        $link = false;

        if ($user === null) {
            $raw = $sso->getRaw();
            $email = (string) $sso->getEmail();
            if ($email === '' || ($raw['email_verified'] ?? false) !== true) {
                $this->refuse('auth.sso_unknown');
            }

            $user = $users->retrieveByCredentials(['email' => $email]);
            // Already linked to another Keycloak account: never taken over by e-mail.
            if ($user === null || $user->keycloak_sub !== null) {
                $this->refuse('auth.sso_unknown');
            }
            $link = true;
        }

        // Before anything else is believed: which company's system vouched for this person. A
        // company's own AD/Entra may send any e-mail at all; it must never reach another company's account.
        if (! $this->vouchedForBy($user, $sso->organizations ?? [])) {
            $this->refuse('auth.sso_refused');
        }

        if (! $this->canSignIn->handle($user, CanSignIn::SSO, $tenantId)) {
            $this->refuse($this->canSignIn->handle($user, CanSignIn::PASSWORD, $tenantId) ? 'auth.sso_not_enabled' : 'auth.sso_refused');
        }

        if ($link) {
            $this->tenants->run($user->tenant_id, fn () => $user->forceFill(['keycloak_sub' => $sub])->save());
        }

        return $user;
    }

    /**
     * The sign-in came through the right place for the account's company (that of the main
     * account): its Keycloak Organization for a company on its own system, and no Organization
     * at all — an account in Keycloak itself — for any other company.
     *
     * @param  list<string>  $organizations  from the ID token (KeycloakProvider)
     */
    private function vouchedForBy(User $user, array $organizations): bool
    {
        $tenant = $user->tenant;

        if ($tenant?->sso_source === Tenant::SSO_IDP) {
            return $tenant->keycloak_org !== null && in_array($tenant->keycloak_org, $organizations, true);
        }

        return $organizations === [];
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['email' => __($message)])->redirectTo(route('login'));
    }
}
