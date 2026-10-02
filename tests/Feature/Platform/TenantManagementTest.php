<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Models\Activity;
use App\Modules\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->superadmin = createSuperadmin(['name' => 'Root']);

    $this->payload = [
        'name' => 'บริษัท ไอทีเซอร์วิส จำกัด', 'subdomain' => 'itservice', 'status' => 'active',
        'subscription_starts_on' => '2026-07-01', 'subscription_ends_on' => '2027-06-30',
        'admin_name' => 'คุณสมชาย', 'admin_email' => 'admin@itservice.test',
        'admin_password' => 'password-123', 'admin_password_confirmation' => 'password-123',
    ];
});

it('creates a customer company with its roles and first admin', function () {
    $this->actingAs($this->superadmin)->get('/platform/tenants/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Platform/Tenants/Form')->where('tenant', null)->where('warnDays', 30));

    $this->actingAs($this->superadmin)->post('/platform/tenants', $this->payload)
        ->assertRedirect(route('platform.impersonation.index'))->assertSessionHasNoErrors();

    $tenant = Tenant::where('subdomain', 'itservice')->sole();
    expect($tenant->only(['name', 'slug', 'status', 'is_platform']))->toBe([
        'name' => 'บริษัท ไอทีเซอร์วิส จำกัด', 'slug' => 'itservice', 'status' => 'active', 'is_platform' => false,
    ])
        ->and($tenant->subscription_starts_on->toDateString())->toBe('2026-07-01')
        ->and($tenant->subscription_ends_on->toDateString())->toBe('2027-06-30');

    asTenant($tenant, function () {
        $admin = User::where('email', 'admin@itservice.test')->sole();
        expect($admin->hasRole('admin_company'))->toBeTrue()
            ->and($admin->can('users.manage'))->toBeTrue()
            ->and(Role::count())->toBe(6);
    });

    // the new admin can sign in to their company
    $this->post('/logout');
    $this->post('/login', ['email' => 'admin@itservice.test', 'password' => 'password-123'])->assertRedirect(route('dashboard', absolute: false));
});

it('validates the company and its admin', function () {
    createTenant('taken');
    createUserInOtherTenant: asTenant(createTenant('someone'), fn () => userWithRole('user', ['email' => 'used@x.test']));

    $this->actingAs($this->superadmin)->post('/platform/tenants', [
        ...$this->payload,
        'subdomain' => 'Bad Name', 'subscription_ends_on' => '2026-01-01', 'admin_email' => 'used@x.test', 'admin_password_confirmation' => 'nope',
    ])->assertSessionHasErrors(['subdomain', 'subscription_ends_on', 'admin_email', 'admin_password']);

    $this->actingAs($this->superadmin)->post('/platform/tenants', [...$this->payload, 'subdomain' => 'taken'])->assertSessionHasErrors('subdomain');
    $this->actingAs($this->superadmin)->post('/platform/tenants', [...$this->payload, 'subdomain' => 'admin'])->assertSessionHasErrors('subdomain');

    expect(Tenant::where('subdomain', 'itservice')->exists())->toBeFalse();
});

it('changes the name, status and period of a company and logs it', function () {
    $company = createTenant('acme');

    $this->actingAs($this->superadmin)->get("/platform/tenants/{$company->ulid}/edit")
        ->assertInertia(fn (Assert $page) => $page->where('tenant.subdomain', 'acme')->where('tenant.subscription.state', 'unlimited'));

    $this->actingAs($this->superadmin)->put("/platform/tenants/{$company->ulid}", [
        'name' => 'Acme Renamed', 'subdomain' => 'acme', 'status' => 'suspended',
        'subscription_starts_on' => '2026-01-01', 'subscription_ends_on' => '2026-12-31',
    ])->assertRedirect(route('platform.impersonation.index'))->assertSessionHasNoErrors();

    $company->refresh();
    expect($company->only(['name', 'status']))->toBe(['name' => 'Acme Renamed', 'status' => 'suspended'])
        ->and($company->subscription_ends_on->toDateString())->toBe('2026-12-31');

    $log = asTenant($this->superadmin->tenant, fn () => Activity::where('event', 'tenant_updated')->sole());
    expect($log->properties['old']['subscription_ends_on'])->toBeNull()
        ->and($log->properties['attributes']['subscription_ends_on'])->toBe('2026-12-31')
        ->and($log->properties['actor']['name'])->toBe('Root');

    // admin fields belong to creating only
    $this->actingAs($this->superadmin)->put("/platform/tenants/{$company->ulid}", [
        'name' => 'X', 'subdomain' => 'acme', 'status' => 'active', 'admin_email' => 'x@x.test',
    ])->assertSessionHasErrors('admin_email');

    // the list shows where each company stands
    $this->actingAs($this->superadmin)->get('/platform/impersonation?search=acme')
        ->assertInertia(fn (Assert $page) => $page->where('tenants.data.0.subscription.ends_on', '2026-12-31'));
});

it('keeps company management for the superadmin at home', function () {
    $company = createTenant('acme');
    $central = userWithRole(PermissionCatalog::CENTRAL_HELPDESK, [], $this->superadmin->tenant);

    $this->actingAs($central)->get('/platform/tenants/create')->assertForbidden();
    $this->actingAs($central)->post('/platform/tenants', $this->payload)->assertForbidden();
    $this->actingAs($central)->put("/platform/tenants/{$company->ulid}", ['name' => 'X', 'subdomain' => 'acme', 'status' => 'active'])->assertForbidden();
    $this->actingAs(userWithRole('admin_company'))->get("/platform/tenants/{$company->ulid}/edit")->assertForbidden();

    // not while working inside a company either
    $this->actingAs($this->superadmin)->post("/platform/impersonation/{$company->ulid}");
    $this->actingAs($this->superadmin)->get('/platform/tenants/create')->assertForbidden();
    $this->actingAs($this->superadmin)->delete('/platform/impersonation');

    // the platform tenant itself is not a company to edit
    $this->actingAs($this->superadmin)->get("/platform/tenants/{$this->superadmin->tenant->ulid}/edit")->assertNotFound();
});
