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
        $claims = is_string($idToken) ? $this->claims($idToken) : [];

        return parent::userInstance($response, $user)->map([
            'idToken' => $idToken,
            'sid' => $claims['sid'] ?? null,
            'organizations' => $this->organizations($claims['organization'] ?? null),
        ]);
    }

    /**
     * The Keycloak Organizations the sign-in came through, by alias. The claim is a list of
     * aliases, or an object keyed by alias when the mapper adds the organizations' ids.
     *
     * @return list<string>
     */
    private function organizations(mixed $claim): array
    {
        if (! is_array($claim)) {
            return [];
        }
        $aliases = array_is_list($claim) ? $claim : array_keys($claim);

        return array_values(array_filter($aliases, fn ($alias) => is_string($alias) && $alias !== ''));
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1] ?? '';
        $json = base64_decode(strtr($payload, '-_', '+/'), true);

        return is_string($json) ? (array) json_decode($json, true) : [];
    }
}
