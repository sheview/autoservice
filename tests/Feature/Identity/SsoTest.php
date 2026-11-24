<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\KeycloakProvider;
use App\Modules\Tenancy\Models\Tenant;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * Sign in with Keycloak: who gets in, how the account is found, and signing out of Keycloak too.
 * Keycloak itself is replaced by a stub provider returning the person it "signed in".
 */

beforeEach(function () {
    config([
        'services.keycloak.client_id' => 'governix',
        'services.keycloak.client_secret' => 'secret',
        'services.keycloak.base_url' => 'https://sso.test',
    ]);

    $this->user = userWithRole('admin_company', ['email' => 'somchai@example.com']);
    $this->user->tenant->update(['auth_mode' => Tenant::AUTH_BOTH]);
    auth()->logout();

    // Who Keycloak says signed in (null: the sign-in failed at Keycloak).
    $this->keycloak = function (?array $person) {
        $provider = Mockery::mock(KeycloakProvider::class);
        $provider->shouldReceive('setConfig', 'scopes', 'enablePKCE')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://sso.test/auth'));
        $provider->shouldReceive('getLogoutUrl')->andReturnUsing(
            fn ($back, $client, $token) => 'https://sso.test/logout?id_token_hint='.$token.'&post_logout_redirect_uri='.urlencode($back)
        );
        $person === null
            ? $provider->shouldReceive('user')->andThrow(new RuntimeException('access_denied'))
            : $provider->shouldReceive('user')->andReturn((new SocialiteUser)->setRaw($person)->map([
                'id' => $person['sub'] ?? null,
                'email' => $person['email'] ?? null,
                'name' => $person['name'] ?? null,
                'idToken' => 'the-id-token',
            ]));
        Socialite::shouldReceive('buildProvider')->andReturn($provider);
    };

    // Through Keycloak and back, starting on $host.
    $this->signIn = function (?array $person, string $host = 'http://localhost') {
        ($this->keycloak)($person);
        $this->get("{$host}/auth/sso")->assertRedirect('https://sso.test/auth');

        return $this->get("{$host}/auth/sso/callback?code=abc&state=xyz");
    };

    $this->verified = ['sub' => 'kc-1', 'email' => 'somchai@example.com', 'email_verified' => true, 'name' => 'Somchai'];
});

it('shows the SSO button only when Keycloak is set up', function () {
    $this->get('/login')->assertInertia(fn ($page) => $page->where('sso', true));

    config(['services.keycloak.client_id' => null]);
    $this->get('/login')->assertInertia(fn ($page) => $page->where('sso', false));
    $this->get('/auth/sso')->assertNotFound();
});

it('links the account by verified e-mail the first time, then finds it by the Keycloak id', function () {
    ($this->signIn)($this->verified)->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->keycloak_sub)->toBe('kc-1')
        ->and(session('sso.id_token'))->toBe('the-id-token');

    // the e-mail changed in Keycloak: still the same account, by its id
    auth()->logout();
    ($this->signIn)(['sub' => 'kc-1', 'email' => 'new@example.com', 'email_verified' => true]);
    $this->assertAuthenticatedAs($this->user);
});

it('never links by an e-mail Keycloak has not verified', function () {
    ($this->signIn)([...$this->verified, 'email_verified' => false])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.sso_unknown')]);

    $this->assertGuest();
    expect($this->user->fresh()->keycloak_sub)->toBeNull();
});

it('does not hand an account linked to one Keycloak account to another with the same e-mail', function () {
    $this->user->forceFill(['keycloak_sub' => 'kc-1'])->save();

    ($this->signIn)([...$this->verified, 'sub' => 'kc-2'])->assertSessionHasErrors(['email' => __('auth.sso_unknown')]);

    $this->assertGuest();
    expect($this->user->fresh()->keycloak_sub)->toBe('kc-1');
});

it('creates no account for a person Governix does not know', function () {
    ($this->signIn)(['sub' => 'kc-9', 'email' => 'stranger@example.com', 'email_verified' => true])
        ->assertSessionHasErrors(['email' => __('auth.sso_unknown')]);

    $this->assertGuest();
    expect(User::withoutGlobalScopes()->where('email', 'stranger@example.com')->exists())->toBeFalse();
});

it('keeps a company that has not turned SSO on to passwords', function () {
    $this->user->tenant->update(['auth_mode' => Tenant::AUTH_PASSWORD]);

    ($this->signIn)($this->verified)->assertSessionHasErrors(['email' => __('auth.sso_not_enabled')]);
    $this->assertGuest();
});

it('does not let in an account that was turned off', function () {
    $this->user->update(['is_active' => false]);

    ($this->signIn)($this->verified)->assertSessionHasErrors(['email' => __('auth.sso_refused')]);
    $this->assertGuest();
});

it('says so when the sign-in failed at Keycloak', function () {
    ($this->signIn)(null)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.sso_failed')]);
    $this->assertGuest();
});

it('does not let a user of one company in on another company\'s subdomain', function () {
    $other = createTenant('other');
    $other->update(['auth_mode' => Tenant::AUTH_BOTH]);

    ($this->signIn)($this->verified, 'http://other.localhost')->assertSessionHasErrors(['email' => __('auth.sso_refused')]);
    $this->assertGuest();

    // on their own company's subdomain they get in, and back to that subdomain
    ($this->signIn)($this->verified, 'http://default.localhost')->assertRedirect('http://default.localhost/dashboard');
    $this->assertAuthenticatedAs($this->user);
});

it('asks for SSO instead of a password in a company that signs in with SSO only', function () {
    $this->user->tenant->update(['auth_mode' => Tenant::AUTH_SSO_ONLY]);

    // the right password: told to use SSO; a wrong one: nothing told
    $this->post('/login', ['email' => 'somchai@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.use_sso')]);
    $this->post('/login', ['email' => 'somchai@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();

    ($this->signIn)($this->verified);
    $this->assertAuthenticatedAs($this->user);
});

it('keeps customers on their password, even in a company that signs in with SSO only', function () {
    $this->user->tenant->update(['auth_mode' => Tenant::AUTH_SSO_ONLY]);
    $customer = User::factory()->create(['email' => 'buyer@example.com', 'customer_id' => createCustomer()->id]);

    $this->post('/login', ['email' => 'buyer@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($customer);

    auth()->logout();
    ($this->signIn)(['sub' => 'kc-5', 'email' => 'buyer@example.com', 'email_verified' => true])
        ->assertSessionHasErrors(['email' => __('auth.sso_not_enabled')]);
    $this->assertGuest();
});

it('signs out of Keycloak as well, back to the login page', function () {
    ($this->signIn)($this->verified);

    $this->post('/logout', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://sso.test/logout?id_token_hint=the-id-token&post_logout_redirect_uri='.urlencode(route('login')));
    $this->assertGuest();

    // signed in with a password: straight to the login page
    $this->post('/login', ['email' => 'somchai@example.com', 'password' => 'password']);
    $this->post('/logout')->assertRedirect(route('login'));
});
