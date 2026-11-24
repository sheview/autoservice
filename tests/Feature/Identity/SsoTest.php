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
    // One stub for the whole test, answering with whoever was set last (Mockery would keep
    // answering with the first of several stubs).
    $provider = Mockery::mock(KeycloakProvider::class);
    Socialite::shouldReceive('buildProvider')->andReturn($provider);
    $this->keycloak = function (?array $person) use ($provider) {
        $this->person = $person;
        if ($this->stubbed ?? false) {
            return;
        }
        $this->stubbed = true;
        $provider->shouldReceive('setConfig', 'enablePKCE')->andReturnSelf();
        $provider->shouldReceive('scopes')->andReturnUsing(function (array $scopes) use ($provider) {
            $this->scopes = $scopes;

            return $provider;
        });
        $provider->shouldReceive('redirect')->andReturn(redirect('https://sso.test/auth'));
        $provider->shouldReceive('getLogoutUrl')->andReturnUsing(
            fn ($back, $client, $token) => 'https://sso.test/logout?id_token_hint='.$token.'&post_logout_redirect_uri='.urlencode($back)
        );
        $provider->shouldReceive('user')->andReturnUsing(function () {
            $person = $this->person;
            if ($person === null) {
                throw new RuntimeException('access_denied');
            }

            return (new SocialiteUser)->setRaw($person)->map([
                'id' => $person['sub'] ?? null,
                'email' => $person['email'] ?? null,
                'name' => $person['name'] ?? null,
                'idToken' => 'the-id-token',
                'organizations' => $person['organizations'] ?? [],
            ]);
        });
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

/*
 * Companies on their own system (AD/Entra) behind Keycloak, each through its own Keycloak
 * Organization: a company's system vouches for its own people only.
 */

it('lets in a person of a company on its own system only through that company\'s Organization', function () {
    config(['services.keycloak.organizations' => true]);
    $this->user->tenant->update(['sso_source' => Tenant::SSO_IDP, 'keycloak_org' => 'default-co']);

    // not through any Organization (an account in Keycloak itself), or through another company's
    ($this->signIn)($this->verified)->assertSessionHasErrors(['email' => __('auth.sso_refused')]);
    ($this->signIn)([...$this->verified, 'organizations' => ['acme']])->assertSessionHasErrors(['email' => __('auth.sso_refused')]);
    $this->assertGuest();
    expect($this->user->fresh()->keycloak_sub)->toBeNull();

    ($this->signIn)([...$this->verified, 'organizations' => ['default-co']])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->keycloak_sub)->toBe('kc-1');
});

it('never lets one company\'s system take over another company\'s account with its e-mail', function () {
    config(['services.keycloak.organizations' => true]);
    $acme = createTenant('acme');
    $acme->update(['auth_mode' => Tenant::AUTH_BOTH, 'sso_source' => Tenant::SSO_IDP, 'keycloak_org' => 'acme']);
    $beta = createTenant('beta');
    $beta->update(['auth_mode' => Tenant::AUTH_BOTH, 'sso_source' => Tenant::SSO_IDP, 'keycloak_org' => 'beta']);
    $victim = userWithRole('admin_company', ['email' => 'boss@beta.test'], $beta);

    // Acme's AD says "this is boss@beta.test, e-mail verified" — on Beta's subdomain too
    foreach (['http://localhost', 'http://beta.localhost'] as $host) {
        ($this->signIn)(['sub' => 'kc-evil', 'email' => 'boss@beta.test', 'email_verified' => true, 'organizations' => ['acme']], $host)
            ->assertSessionHasErrors(['email' => __('auth.sso_refused')]);
    }

    // nor an account in Keycloak itself (no Organization) for a company on its own system, nor
    // a company account by way of a company's Organization
    ($this->signIn)(['sub' => 'kc-evil', 'email' => 'boss@beta.test', 'email_verified' => true]);
    ($this->signIn)([...$this->verified, 'sub' => 'kc-evil', 'organizations' => ['acme']]);

    $this->assertGuest();
    expect(asTenant($beta, fn () => $victim->fresh()->keycloak_sub))->toBeNull()
        ->and($this->user->fresh()->keycloak_sub)->toBeNull();
});

it('sends people straight to their company\'s own system from its subdomain, and always asks which Organization', function () {
    config(['services.keycloak.organizations' => true]);
    $this->user->tenant->update(['sso_source' => Tenant::SSO_IDP, 'keycloak_org' => 'default-co']);

    ($this->keycloak)(null);
    $this->get('http://default.localhost/auth/sso');
    expect($this->scopes)->toBe(['profile', 'email', 'organization:default-co']);

    $this->get('http://localhost/auth/sso');
    expect($this->scopes)->toBe(['profile', 'email', 'organization']);

    // Organizations off in Keycloak: not asked for (Keycloak would refuse an unknown scope)
    config(['services.keycloak.organizations' => false]);
    $this->get('http://default.localhost/auth/sso');
    expect($this->scopes)->toBe(['profile', 'email']);
});

it('keeps the company form\'s Organization to companies on their own system', function () {
    $superadmin = createSuperadmin();
    $update = fn (array $data) => $this->actingAs($superadmin)->put(route('platform.tenants.update', $this->tenant->ulid), [
        'name' => 'Default', 'subdomain' => 'default', 'status' => 'active', 'auth_mode' => Tenant::AUTH_BOTH, ...$data,
    ]);

    $update(['sso_source' => Tenant::SSO_IDP])->assertSessionHasErrors('keycloak_org');
    $update(['sso_source' => Tenant::SSO_IDP, 'keycloak_org' => 'bad alias!'])->assertSessionHasErrors('keycloak_org');

    $update(['sso_source' => Tenant::SSO_IDP, 'keycloak_org' => 'default-co'])->assertSessionHasNoErrors();
    expect($this->tenant->fresh()->only('sso_source', 'keycloak_org'))->toBe(['sso_source' => 'idp', 'keycloak_org' => 'default-co']);

    $other = createTenant('other');
    $this->actingAs($superadmin)->put(route('platform.tenants.update', $other->ulid), [
        'name' => 'Other', 'subdomain' => 'other', 'status' => 'active', 'sso_source' => Tenant::SSO_IDP, 'keycloak_org' => 'default-co',
    ])->assertSessionHasErrors('keycloak_org');

    // back to accounts in Keycloak: the Organization goes, or its sign-ins would still count
    $update(['sso_source' => Tenant::SSO_KEYCLOAK, 'keycloak_org' => 'default-co'])->assertSessionHasNoErrors();
    expect($this->tenant->fresh()->keycloak_org)->toBeNull();
});
