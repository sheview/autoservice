<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Activity;
use App\Modules\Platform\Support\Impersonation;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->superadmin = createSuperadmin(['name' => 'Real Superadmin', 'email' => 'root@platform.test']);
    $this->customer = createTenant('customer');
});

function customerActivities($test)
{
    return asTenant($test->customer, fn () => Activity::orderBy('id')->get());
}

it('logs every action of an impersonating superadmin with their real name', function () {
    $this->actingAs($this->superadmin)
        ->post("/platform/impersonation/{$this->customer->ulid}")
        ->assertRedirect(route('dashboard'));

    $this->actingAs($this->superadmin)->get('/users')->assertOk();
    $this->actingAs($this->superadmin)->post('/users', [
        'name' => 'Made By Superadmin', 'email' => 'made@customer.test',
        'password' => 'password-123', 'password_confirmation' => 'password-123', 'role' => 'user',
    ])->assertSessionHasNoErrors();

    // the user was created inside the customer tenant
    $created = asTenant($this->customer, fn () => User::where('email', 'made@customer.test')->first());
    expect($created)->not->toBeNull()->and($created->tenant_id)->toBe($this->customer->id);

    $logs = customerActivities($this);
    expect($logs->pluck('description')->all())->toContain('เริ่มเข้าดูในนามบริษัท', 'GET /users', 'POST /users', 'created');

    // every entry names the real person and is marked as impersonation
    foreach ($logs as $log) {
        expect($log->properties['actor']['name'])->toBe('Real Superadmin')
            ->and($log->properties['actor']['email'])->toBe('root@platform.test')
            ->and($log->causer_id)->toBe($this->superadmin->id);
    }
    expect($logs->where('description', '!=', 'เริ่มเข้าดูในนามบริษัท')->every(fn ($log) => $log->properties['impersonating'] === true))->toBeTrue();
});

it('shows the impersonation banner data on every page', function () {
    $this->actingAs($this->superadmin)->post("/platform/impersonation/{$this->customer->ulid}");

    $this->actingAs($this->superadmin)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.tenant.name', 'Customer')
            ->where('tenant.name', 'Customer'));
});

it('ends impersonation and logs it', function () {
    $this->actingAs($this->superadmin)->post("/platform/impersonation/{$this->customer->ulid}");

    $this->actingAs($this->superadmin)->delete('/platform/impersonation')
        ->assertRedirect(route('platform.impersonation.index'));

    $this->actingAs($this->superadmin)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('impersonation', null)->where('tenant.name', 'Platform'));

    expect(customerActivities($this)->pluck('event')->all())->toContain('started', 'stopped');
});

it('does not let a customer admin impersonate', function () {
    $admin = userWithRole('admin_company');

    $this->actingAs($admin)->post("/platform/impersonation/{$this->customer->ulid}")->assertForbidden();
    $this->actingAs($admin)->get('/platform/impersonation')->assertForbidden();
});

it('stops impersonating when the superadmin loses the permission', function () {
    $this->actingAs($this->superadmin)->post("/platform/impersonation/{$this->customer->ulid}");

    $platform = $this->superadmin->tenant;
    asTenant($platform, fn () => Role::findByName('superadmin')->revokePermissionTo(Impersonation::PERMISSION));

    $this->actingAs($this->superadmin)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('impersonation', null)->where('tenant.name', 'Platform'));
});

it('lists customer tenants for the superadmin', function () {
    $this->actingAs($this->superadmin)->get('/platform/impersonation')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Impersonation/Index')
            ->where('tenants.data', fn ($tenants) => collect($tenants)->pluck('name')->sort()->values()->all() === ['Customer', 'Default']));
});
