<?php

namespace App\Modules\Identity\Support;

use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config;

/**
 * Sign-in with Keycloak (config/services.php "keycloak"). Off until its client id and URL are set.
 */
class Sso
{
    public const DRIVER = 'keycloak';

    /** Session key of the ID token, for signing out of Keycloak as well. */
    public const ID_TOKEN = 'sso.id_token';

    /** Session keys of the Keycloak session, the person, and when they signed in (back-channel logout). */
    public const SID = 'sso.sid';

    public const SUB = 'sso.sub';

    public const SIGNED_IN_AT = 'sso.signed_in_at';

    /** Cache keys of Keycloak sessions ended there: one session, or all of a person's up to a time. */
    public const ENDED_SID = 'sso.ended.sid.';

    public const ENDED_SUB = 'sso.ended.sub.';

    public static function enabled(): bool
    {
        return filled(config('services.keycloak.client_id')) && filled(config('services.keycloak.base_url'));
    }

    public static function organizationsEnabled(): bool
    {
        return (bool) config('services.keycloak.organizations');
    }

    /**
     * @param  string|null  $organization  the company's Keycloak Organization: Keycloak then signs the
     *                                     person in through that company's own system (AD/Entra) straight away.
     */
    public static function driver(?string $organization = null): KeycloakProvider
    {
        $config = config('services.keycloak');

        /** @var KeycloakProvider $driver */
        $driver = Socialite::buildProvider(KeycloakProvider::class, [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect' => self::callbackUrl(),
        ]);

        return $driver
            ->setConfig(new Config($config['client_id'], $config['client_secret'], self::callbackUrl(), [
                'base_url' => $config['base_url'],
                'realms' => $config['realms'],
            ]))
            ->scopes(self::scopes($organization))
            ->enablePKCE();
    }

    /** Keycloak's own sign-out, then back to the login page (with the ID token: no question asked). */
    public static function logoutUrl(?string $idToken): string
    {
        return self::driver()->getLogoutUrl(route('login'), config('services.keycloak.client_id'), $idToken);
    }

    /**
     * With Organizations on, always the "organization" scope: without it Keycloak leaves the
     * organization claim out, and SignInWithSso could not tell which company's system vouched
     * for the person.
     *
     * @return list<string>
     */
    public static function scopes(?string $organization): array
    {
        $scopes = ['profile', 'email'];
        if (self::organizationsEnabled()) {
            $scopes[] = $organization !== null ? 'organization:'.$organization : 'organization';
        }

        return $scopes;
    }

    private static function callbackUrl(): string
    {
        return config('services.keycloak.redirect') ?: route('sso.callback');
    }
}
