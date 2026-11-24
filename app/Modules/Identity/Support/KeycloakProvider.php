<?php

namespace App\Modules\Identity\Support;

use Illuminate\Support\Arr;
use SocialiteProviders\Keycloak\Provider;

/**
 * The Keycloak provider, keeping from the sign-in:
 *  - the ID token: signing out of Keycloak with it (id_token_hint) ends the Keycloak session
 *    too, without Keycloak asking "Do you want to log out?";
 *  - its session id ("sid"): a back-channel logout from Keycloak names the session it ended.
 *    Read without checking the signature: the token came straight from Keycloak's token
 *    endpoint (over TLS, with our client secret), not through the browser.
 */
class KeycloakProvider extends Provider
{
    protected function userInstance(array $response, array $user)
    {
        $idToken = Arr::get($response, 'id_token');

        return parent::userInstance($response, $user)->map([
            'idToken' => $idToken,
            'sid' => is_string($idToken) ? $this->claims($idToken)['sid'] ?? null : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1] ?? '';
        $json = base64_decode(strtr($payload, '-_', '+/'), true);

        return is_string($json) ? (array) json_decode($json, true) : [];
    }
}
