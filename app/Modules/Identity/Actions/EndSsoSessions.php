<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Support\Sso;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Keycloak's back-channel logout: a person signed out at Keycloak (or another app, or an admin
 * ended their session there), and Keycloak tells us so, server to server, with a signed logout
 * token. The ended session is remembered here; the person's Governix session ends on its next
 * request (Http\Middleware\EndSsoSession), whichever session store is used.
 *
 * Returns false for a token that is not a valid logout token of our realm and client.
 */
class EndSsoSessions
{
    private const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    /** Longer than any session lasts. */
    private const REMEMBER_SECONDS = 2 * 24 * 3600;

    public function handle(string $logoutToken): bool
    {
        $claims = $this->verify($logoutToken);
        if ($claims === null) {
            return false;
        }

        $sid = $claims['sid'] ?? null;
        $sub = $claims['sub'] ?? null;

        if (is_string($sid) && $sid !== '') {
            Cache::put(Sso::ENDED_SID.$sid, true, self::REMEMBER_SECONDS);
        } else {
            // Every session of the person, up to now.
            Cache::put(Sso::ENDED_SUB.$sub, time(), self::REMEMBER_SECONDS);
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function verify(string $token): ?array
    {
        $config = config('services.keycloak');
        $issuer = rtrim($config['base_url'], '/').'/realms/'.$config['realms'];

        try {
            // Signature, exp, iat, nbf (firebase/php-jwt), with the realm's published keys.
            $claims = (array) JWT::decode($token, JWK::parseKeySet($this->keys($issuer)));
        } catch (Throwable) {
            return null;
        }

        $audience = (array) ($claims['aud'] ?? []);
        $events = (array) ($claims['events'] ?? []);
        $valid = ($claims['iss'] ?? null) === $issuer
            && in_array($config['client_id'], $audience, true)
            && array_key_exists(self::EVENT, $events)
            && ! array_key_exists('nonce', $claims) // never an ID token passed off as one
            && (filled($claims['sid'] ?? null) || filled($claims['sub'] ?? null));

        // Each logout token is used once.
        $fresh = ! isset($claims['jti']) || Cache::add('sso.logout_jti.'.$claims['jti'], true, self::REMEMBER_SECONDS);

        return $valid && $fresh ? $claims : null;
    }

    /**
     * The realm's signing keys (not its encryption key, also listed there).
     *
     * @return array{keys: list<array<string, mixed>>}
     */
    private function keys(string $issuer): array
    {
        $jwks = Cache::remember('keycloak.jwks', 3600, fn () => Http::timeout(10)
            ->get($issuer.'/protocol/openid-connect/certs')
            ->throw()
            ->json());

        return ['keys' => array_values(array_filter((array) ($jwks['keys'] ?? []), fn ($key) => ($key['use'] ?? 'sig') === 'sig'))];
    }
}
