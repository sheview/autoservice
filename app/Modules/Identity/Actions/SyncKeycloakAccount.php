<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\KeycloakAdmin;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Makes a staff member's Keycloak account match their Governix account (Governix is where
 * accounts are made): a new one gets a Keycloak account and an e-mail to set its password; a
 * changed name or e-mail is copied; one turned off is turned off in Keycloak and signed out.
 *
 * Only main accounts of staff, in a company that signs in with SSO: a linked row is the same
 * person as its main account, and customers keep their password.
 */
class SyncKeycloakAccount
{
    public function __construct(private KeycloakAdmin $keycloak) {}

    public function handle(User $user): void
    {
        if (! KeycloakAdmin::enabled() || $user->login_user_id !== null || $user->customer_id !== null) {
            return;
        }
        if (in_array($user->tenant?->auth_mode, [null, Tenant::AUTH_PASSWORD], true)) {
            return;
        }

        // A company on its own system (AD/Entra): name and e-mail come from there, and its people
        // set no password with us; their account here is linked on their first sign-in. Turning
        // one off in Governix still turns it off in Keycloak, whatever their own system says.
        $ownSystem = $user->tenant->sso_source === Tenant::SSO_IDP;
        $profile = $ownSystem
            ? ['enabled' => $user->is_active]
            : ['email' => $user->email, 'firstName' => $user->name, 'enabled' => $user->is_active];

        if ($user->keycloak_sub !== null) {
            $this->keycloak->update($user->keycloak_sub, $profile);
            if (! $user->is_active) {
                $this->keycloak->logout($user->keycloak_sub);
            }

            return;
        }

        if (! $user->is_active || $ownSystem) {
            return;
        }

        $existing = $this->keycloak->findByEmail($user->email);
        if ($existing !== null) {
            // An account someone made with this e-mail but never proved it: not theirs to take over.
            if (($existing['emailVerified'] ?? false) !== true) {
                Log::warning('Keycloak account with an unverified e-mail not linked', ['user_id' => $user->id, 'keycloak_id' => $existing['id']]);

                return;
            }
            $this->link($user, $existing['id']);
            $this->keycloak->update($existing['id'], $profile);

            return;
        }

        // The person proves the e-mail by using the link sent to it.
        $id = $this->keycloak->create([...$profile, 'username' => $user->email, 'emailVerified' => true]);
        $this->link($user, $id);
        $this->keycloak->sendSetupEmail($id);
    }

    private function link(User $user, string $id): void
    {
        $user->forceFill(['keycloak_sub' => $id])->save();
    }
}
