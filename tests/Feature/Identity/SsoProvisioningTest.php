<?php

use App\Modules\Identity\Actions\SaveUser;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Governix is where accounts are made: Keycloak accounts follow (SyncKeycloakAccount). Keycloak's
 * Admin API is replaced by a small in-memory Keycloak.
 */

beforeEach(function () {
    config([
        'services.keycloak.client_id' => 'governix',
        'services.keycloak.client_secret' => 'secret',
        'services.keycloak.base_url' => 'https://sso.test',
        'services.keycloak.provision' => true,
    ]);
    $this->tenant->update(['auth_mode' => Tenant::AUTH_BOTH]);

    // id => account
    $this->accounts = [];
    $this->emails = [];
    $accounts = &$this->accounts;
    $emails = &$this->emails;
    $admin = 'https://sso.test/admin/realms/governix/users';

    Http::fake(function (Request $request) use (&$accounts, &$emails, $admin) {
        $url = $request->url();
        if (str_ends_with($url, '/protocol/openid-connect/token')) {
            return Http::response(['access_token' => 'admin-token']);
        }
        if ($request->method() === 'GET' && str_starts_with($url, $admin.'?')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return Http::response(array_values(array_filter($accounts, fn ($a) => $a['email'] === $query['email'])));
        }
        if ($request->method() === 'POST' && $url === $admin) {
            $id = 'kc-'.(count($accounts) + 1);
            $accounts[$id] = ['id' => $id, ...$request->data()];

            return Http::response('', 201, ['Location' => "{$admin}/{$id}"]);
        }
        if (preg_match('#/users/([^/?]+)/execute-actions-email#', $url, $m)) {
            $emails[] = [$m[1], $request->data()];

            return Http::response('', 204);
        }
        if (preg_match('#/users/([^/?]+)/logout$#', $url, $m)) {
            $accounts[$m[1]]['signed_out'] = true;

            return Http::response('', 204);
        }
        if ($request->method() === 'PUT' && preg_match('#/users/([^/?]+)$#', $url, $m)) {
            $accounts[$m[1]] = [...$accounts[$m[1]], ...$request->data()];

            return Http::response('', 204);
        }

        return Http::response('', 404);
    });

    $this->save = fn (?User $user, array $data) => app(SaveUser::class)->handle($user, $data + ['role' => 'user']);
    userWithRole('user'); // the role exists
});

it('gives a new staff member a Keycloak account and an e-mail to set its password', function () {
    $user = ($this->save)(null, ['name' => 'Somsri', 'email' => 'somsri@example.com']);

    expect($user->fresh()->keycloak_sub)->toBe('kc-1')
        ->and($this->accounts['kc-1'])->toMatchArray(['username' => 'somsri@example.com', 'email' => 'somsri@example.com', 'firstName' => 'Somsri', 'enabled' => true])
        ->and($this->emails)->toBe([['kc-1', ['UPDATE_PASSWORD']]]);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer admin-token') || str_ends_with($r->url(), '/token'));
});

it('copies a changed name or e-mail, and turns the account off and signs it out with Governix', function () {
    $user = ($this->save)(null, ['name' => 'Somsri', 'email' => 'somsri@example.com']);

    ($this->save)($user->fresh(), ['name' => 'Somsri K.', 'email' => 'somsri.k@example.com', 'is_active' => true]);
    expect($this->accounts['kc-1'])->toMatchArray(['email' => 'somsri.k@example.com', 'firstName' => 'Somsri K.', 'enabled' => true]);

    ($this->save)($user->fresh(), ['name' => 'Somsri K.', 'email' => 'somsri.k@example.com', 'is_active' => false]);
    expect($this->accounts['kc-1'])->toMatchArray(['enabled' => false, 'signed_out' => true])
        ->and($this->accounts)->toHaveCount(1);
});

it('links a Keycloak account that already has the proven e-mail, but never one with an unproven e-mail', function () {
    $this->accounts['kc-old'] = ['id' => 'kc-old', 'email' => 'somsri@example.com', 'emailVerified' => true];
    $this->accounts['kc-fake'] = ['id' => 'kc-fake', 'email' => 'somchai@example.com', 'emailVerified' => false];

    $linked = ($this->save)(null, ['name' => 'Somsri', 'email' => 'somsri@example.com']);
    $refused = ($this->save)(null, ['name' => 'Somchai', 'email' => 'somchai@example.com']);

    expect($linked->fresh()->keycloak_sub)->toBe('kc-old')
        ->and($refused->fresh()->keycloak_sub)->toBeNull()
        ->and($this->accounts)->toHaveCount(2)
        ->and($this->emails)->toBe([]);
});

it('makes no Keycloak account in a company on passwords, for customers, or for linked rows', function () {
    $this->tenant->update(['auth_mode' => Tenant::AUTH_PASSWORD]);
    ($this->save)(null, ['name' => 'Password person', 'email' => 'pw@example.com']);

    $this->tenant->update(['auth_mode' => Tenant::AUTH_SSO_ONLY]);
    $customer = User::factory()->create(['customer_id' => createCustomer()->id]);
    ($this->save)($customer, ['name' => 'Buyer', 'email' => $customer->email]);

    $main = User::factory()->create(['email' => 'main@example.com', 'keycloak_sub' => null]);
    $linked = User::factory()->create(['email' => 'linked@example.com']);
    $linked->forceFill(['login_user_id' => $main->id])->save();
    ($this->save)($linked->fresh(), ['name' => 'Linked', 'email' => 'linked@example.com', 'login_user_id' => $main->id]);

    expect($this->accounts)->toBe([]);
});

it('does nothing in Keycloak while provisioning is off', function () {
    config(['services.keycloak.provision' => false]);

    ($this->save)(null, ['name' => 'Somsri', 'email' => 'somsri@example.com']);

    Http::assertNothingSent();
});

it('gives every staff member of a company that turns SSO on an account, and nobody of another company', function () {
    $this->tenant->update(['auth_mode' => Tenant::AUTH_PASSWORD]);
    User::factory()->count(2)->create();
    $staff = User::whereNull('customer_id')->get(); // with the one made in beforeEach
    $customer = User::factory()->create(['customer_id' => createCustomer()->id]);

    $other = createTenant('other');
    $otherStaff = asTenant($other, fn () => User::factory()->create());

    $superadmin = createSuperadmin();
    $this->actingAs($superadmin)->get(route('platform.tenants.edit', $this->tenant->ulid))
        ->assertInertia(fn ($page) => $page->where('tenant.auth_mode', 'password')->where('ssoReady', true)->has('authModes', 3));

    $this->actingAs($superadmin)->put(route('platform.tenants.update', $this->tenant->ulid), [
        'name' => 'Default', 'subdomain' => 'default', 'status' => 'active', 'auth_mode' => Tenant::AUTH_SSO_ONLY,
    ])->assertSessionHasNoErrors();

    expect($this->tenant->fresh()->auth_mode)->toBe(Tenant::AUTH_SSO_ONLY)
        ->and(collect($this->accounts)->pluck('email')->sort()->values()->all())->toBe($staff->pluck('email')->sort()->values()->all())
        ->and($customer->fresh()->keycloak_sub)->toBeNull()
        ->and(asTenant($other, fn () => $otherStaff->fresh()->keycloak_sub))->toBeNull();

    // the form refuses a way of signing in that does not exist
    $this->actingAs($superadmin)->put(route('platform.tenants.update', $this->tenant->ulid), [
        'name' => 'Default', 'subdomain' => 'default', 'status' => 'active', 'auth_mode' => 'magic',
    ])->assertSessionHasErrors('auth_mode');
});
