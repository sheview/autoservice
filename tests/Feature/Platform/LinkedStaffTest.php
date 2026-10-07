<?php

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Support\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->superadmin = createSuperadmin();
    $this->home = app(TenantContext::class)->tenant(); // "Default"
    $this->other = createTenant('other');
    $this->tech = asTenant($this->home, fn () => userWithRole('technician', [
        'name' => 'สุรพงศ์', 'email' => 'surapong@itbtthai.com', 'employee_code' => '631001006', 'phone' => '091', 'service_lines' => ['pc'],
    ]));
});

it('lists company staff with the companies they work in', function () {
    asTenant($this->home, fn () => userWithRole('customer_it', ['customer_id' => createCustomer()->id]));

    $this->actingAs($this->superadmin)->get('/platform/linked-staff')
        ->assertInertia(fn (Assert $page) => $page->component('Platform/LinkedStaff/Index')
            ->where('tenants', fn ($tenants) => collect($tenants)->pluck('name')->all() === ['Default', 'Other'])
            ->where('staff.data', fn ($staff) => collect($staff)->pluck('email')->contains('surapong@itbtthai.com')
                && collect($staff)->every(fn ($person) => $person['email'] !== $this->superadmin->email)
                && collect($staff)->firstWhere('email', 'surapong@itbtthai.com')['home'] === $this->home->id));

    $this->actingAs($this->superadmin)->get('/platform/linked-staff?search=631001006')
        ->assertInertia(fn (Assert $page) => $page->where('staff.total', 1));
});

it('lets a person work in another company and stops it again', function () {
    $this->actingAs($this->superadmin)->put("/platform/linked-staff/{$this->tech->id}/{$this->other->ulid}", ['active' => true])
        ->assertSessionHasNoErrors();

    $row = asTenant($this->other, fn () => User::where('login_user_id', $this->tech->id)->sole());
    asTenant($this->other, function () use ($row) {
        expect($row->only(['name', 'email', 'employee_code', 'phone', 'service_lines', 'is_active', 'branch_id']))->toBe([
            'name' => 'สุรพงศ์', 'email' => 'surapong+other@itbtthai.com', 'employee_code' => '631001006', 'phone' => '091',
            'service_lines' => ['pc'], 'is_active' => true, 'branch_id' => null,
        ])->and($row->hasRole('technician'))->toBeTrue();
    });

    // the person can now switch there
    $this->actingAs($this->tech)->post("/switch-company/{$this->other->ulid}")->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($row);

    $this->actingAs($this->superadmin)->put("/platform/linked-staff/{$this->tech->id}/{$this->other->ulid}", ['active' => false])
        ->assertSessionHasNoErrors();
    expect(asTenant($this->other, fn () => $row->fresh()->is_active))->toBeFalse();
    $this->actingAs($this->tech)->post("/switch-company/{$this->other->ulid}")->assertForbidden();

    // on again: the same account comes back (its work and history stay with it)
    $this->actingAs($this->superadmin)->put("/platform/linked-staff/{$this->tech->id}/{$this->other->ulid}", ['active' => true]);
    expect(asTenant($this->other, fn () => User::where('login_user_id', $this->tech->id)->sole()->is($row)))->toBeTrue()
        ->and(asTenant($this->other, fn () => $row->fresh()->is_active))->toBeTrue();
});

it('does not toggle the person\'s own company nor the last admin of a company', function () {
    $this->actingAs($this->superadmin)->put("/platform/linked-staff/{$this->tech->id}/{$this->home->ulid}", ['active' => false])
        ->assertSessionHasErrors('company');

    $admin = asTenant($this->home, fn () => userWithRole('admin_company', ['email' => 'boss@itbtthai.com']));
    $this->actingAs($this->superadmin)->put("/platform/linked-staff/{$admin->id}/{$this->other->ulid}", ['active' => true]);
    $row = asTenant($this->other, fn () => User::where('login_user_id', $admin->id)->sole());
    expect(asTenant($this->other, fn () => $row->hasRole('admin_company')))->toBeTrue();

    // "Other" has no other admin yet: switching its only admin off is refused
    $this->actingAs($this->superadmin)->put("/platform/linked-staff/{$admin->id}/{$this->other->ulid}", ['active' => false])
        ->assertSessionHasErrors('role');
    expect(asTenant($this->other, fn () => $row->fresh()->is_active))->toBeTrue();
});

it('gives each linked account its own e-mail', function () {
    asTenant($this->home, fn () => userWithRole('technician', ['email' => 'surapong+other@itbtthai.com']));

    $this->actingAs($this->superadmin)->put("/platform/linked-staff/{$this->tech->id}/{$this->other->ulid}", ['active' => true]);

    expect(asTenant($this->other, fn () => User::where('login_user_id', $this->tech->id)->value('email')))->toBe('surapong+other2@itbtthai.com');
});

it('is only for the platform', function () {
    $companyAdmin = asTenant($this->home, fn () => userWithRole('admin_company'));

    $this->actingAs($companyAdmin)->get('/platform/linked-staff')->assertForbidden();
    $this->actingAs($companyAdmin)->put("/platform/linked-staff/{$this->tech->id}/{$this->other->ulid}", ['active' => true])->assertForbidden();
    expect(asTenant($this->other, fn () => User::where('login_user_id', $this->tech->id)->exists()))->toBeFalse();

    // customer accounts and unknown people are not managed here
    $customer = asTenant($this->home, fn () => userWithRole('customer_it', ['customer_id' => createCustomer()->id]));
    $this->actingAs($this->superadmin)->put("/platform/linked-staff/{$customer->id}/{$this->other->ulid}", ['active' => true])->assertNotFound();
    $this->actingAs($this->superadmin)->put("/platform/linked-staff/999999/{$this->other->ulid}", ['active' => true])->assertNotFound();
});
