<?php

namespace App\Modules\Identity\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Keycloak's Admin REST API, as the SSO client's own service account (client credentials). In
 * Keycloak: client → Service accounts roles → realm-management: manage-users, view-users.
 * Off unless KEYCLOAK_PROVISION is set: accounts are then made and switched off in Keycloak
 * when they are in Governix (SyncKeycloakAccount).
 */
class KeycloakAdmin
{
    /** How long the e-mailed "set your password" link works. */
    private const SETUP_LINK_SECONDS = 3 * 24 * 3600;

    public static function enabled(): bool
    {
        return Sso::enabled() && (bool) config('services.keycloak.provision');
    }

    /**
     * The Keycloak account with exactly this e-mail, or null.
     *
     * @return array{id: string, email?: string, emailVerified?: bool, enabled?: bool}|null
     */
    public function findByEmail(string $email): ?array
    {
        $users = $this->api()->get('users', ['email' => $email, 'exact' => 'true'])->throw()->json();

        foreach ((array) $users as $user) {
            if (mb_strtolower((string) ($user['email'] ?? '')) === mb_strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * A new account; its id.
     *
     * @param  array<string, mixed>  $profile
     */
    public function create(array $profile): string
    {
        $response = $this->api()->post('users', $profile)->throw();

        // 201 with Location: …/users/{id}
        $id = basename((string) $response->header('Location'));
        if ($id === '' || $id === 'users') {
            throw new RuntimeException('Keycloak did not return the new user id.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function update(string $id, array $profile): void
    {
        $this->api()->put('users/'.rawurlencode($id), $profile)->throw();
    }

    /** E-mails the person a link to set their password (Keycloak's own e-mail, realm SMTP). */
    public function sendSetupEmail(string $id): void
    {
        $query = http_build_query(['lifespan' => self::SETUP_LINK_SECONDS, 'client_id' => config('services.keycloak.client_id'), 'redirect_uri' => route('login')]);

        $this->api()->put('users/'.rawurlencode($id).'/execute-actions-email?'.$query, ['UPDATE_PASSWORD'])->throw();
    }

    /** Ends every Keycloak session of the account (and, by back-channel logout, ours). */
    public function logout(string $id): void
    {
        $this->api()->post('users/'.rawurlencode($id).'/logout')->throw();
    }

    private function api(): PendingRequest
    {
        $config = config('services.keycloak');

        return Http::baseUrl(rtrim($config['base_url'], '/').'/admin/realms/'.rawurlencode($config['realms']).'/')
            ->withToken($this->token())
            ->acceptJson()
            ->timeout(15);
    }

    private function token(): string
    {
        $config = config('services.keycloak');

        // Keycloak's tokens live 5 minutes by default: one a minute is plenty.
        return Cache::remember('keycloak.admin_token', 60, fn () => (string) Http::asForm()
            ->timeout(15)
            ->post(rtrim($config['base_url'], '/').'/realms/'.rawurlencode($config['realms']).'/protocol/openid-connect/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ])
            ->throw()
            ->json('access_token'));
    }
}
