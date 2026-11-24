<?php

use App\Modules\Identity\Support\KeycloakProvider;
use App\Modules\Tenancy\Models\Tenant;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * Keycloak's back-channel logout: a session ended at Keycloak ends here on the next request, and
 * only a logout token signed by the realm, for our client, is believed.
 */

function rsaKey(): array
{
    // PHP on Windows does not find its openssl.cnf by itself.
    $windowsConf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
        + (PHP_OS_FAMILY === 'Windows' && is_file($windowsConf) ? ['config' => $windowsConf] : []);

    $key = openssl_pkey_new($options);
    openssl_pkey_export($key, $private, null, $options);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

    return [$private, ['kty' => 'RSA', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]];
}

beforeEach(function () {
    config([
        'services.keycloak.client_id' => 'governix',
        'services.keycloak.client_secret' => 'secret',
        'services.keycloak.base_url' => 'https://sso.test',
    ]);
    $this->tenant->update(['auth_mode' => Tenant::AUTH_BOTH]);
    $this->user = userWithRole('admin_company', ['email' => 'somchai@example.com', 'keycloak_sub' => 'kc-1']);
    auth()->logout();

    [$this->private, $public] = rsaKey();
    [$this->otherPrivate] = rsaKey();
    Http::fake(['sso.test/realms/governix/protocol/openid-connect/certs' => Http::response(['keys' => [
        [...$public, 'kid' => 'sig-1', 'alg' => 'RS256', 'use' => 'sig'],
        [...$public, 'kid' => 'enc-1', 'alg' => 'RSA-OAEP', 'use' => 'enc'],
    ]])]);

    $this->logoutToken = fn (array $claims = [], ?string $key = null) => JWT::encode([
        'iss' => 'https://sso.test/realms/governix',
        'aud' => 'governix',
        'iat' => time(),
        'exp' => time() + 60,
        'jti' => (string) Str::uuid(),
        'sub' => 'kc-1',
        'sid' => 'session-1',
        'events' => ['http://schemas.openid.net/event/backchannel-logout' => new stdClass],
        ...$claims,
    ], $key ?? $this->private, 'RS256', 'sig-1');

    // Signed in with Keycloak, in Keycloak session "session-1".
    $provider = Mockery::mock(KeycloakProvider::class);
    $provider->shouldReceive('setConfig', 'scopes', 'enablePKCE')->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(redirect('https://sso.test/auth'));
    $provider->shouldReceive('user')->andReturn((new SocialiteUser)->setRaw(['sub' => 'kc-1'])->map([
        'id' => 'kc-1', 'email' => 'somchai@example.com', 'idToken' => 'id-token', 'sid' => 'session-1',
    ]));
    Socialite::shouldReceive('buildProvider')->andReturn($provider);
    $this->get('/auth/sso');
    $this->get('/auth/sso/callback?code=abc&state=xyz');
    $this->assertAuthenticatedAs($this->user);

    $this->backchannel = fn (string $token) => $this->post('/auth/sso/backchannel-logout', ['logout_token' => $token]);
});

it('ends the session Keycloak says it ended, on its next request', function () {
    ($this->backchannel)(($this->logoutToken)())->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
    $this->get('/login')->assertInertia(fn ($page) => $page->where('status', __('auth.sso_ended')));
});

it('ends every session of the person signed in before, when Keycloak names only the person', function () {
    ($this->backchannel)(($this->logoutToken)(['sid' => null]))->assertOk();

    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('leaves the session alone when another session or person was signed out', function () {
    ($this->backchannel)(($this->logoutToken)(['sid' => 'session-2']))->assertOk();
    ($this->backchannel)(($this->logoutToken)(['sid' => null, 'sub' => 'kc-2']))->assertOk();

    $this->get('/dashboard')->assertOk();
    $this->assertAuthenticatedAs($this->user);
});

it('believes no logout token but one signed by the realm for this client, and each only once', function (array $claims, bool $otherKey) {
    ($this->backchannel)(($this->logoutToken)($claims, $otherKey ? $this->otherPrivate : null))->assertStatus(400);

    $this->get('/dashboard')->assertOk();
    $this->assertAuthenticatedAs($this->user);
})->with([
    'signed with another key' => [[], true],
    'for another client' => [['aud' => 'other-app'], false],
    'from another realm' => [['iss' => 'https://sso.test/realms/other'], false],
    'expired' => [['iat' => time() - 600, 'exp' => time() - 300], false],
    'not a logout event' => [['events' => ['something-else' => new stdClass]], false],
    'an ID token (nonce)' => [['nonce' => 'n'], false],
]);

it('does not take the same logout token twice', function () {
    $token = ($this->logoutToken)(['sid' => 'session-2']);

    ($this->backchannel)($token)->assertOk();
    ($this->backchannel)($token)->assertStatus(400);
});

it('refuses an empty or garbled token', function () {
    ($this->backchannel)('')->assertStatus(400);
    ($this->backchannel)('not.a.jwt')->assertStatus(400);
    $this->get('/dashboard')->assertOk();
});
