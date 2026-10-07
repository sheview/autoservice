<?php

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\StaffPool;
use App\Modules\Tenancy\Support\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->superadmin = createSuperadmin();
    $this->itbt = app(TenantContext::class)->tenant(); // "Default"
    $this->datacom = createTenant('datacom');
    [$this->tech1, $this->tech2, $this->helpdesk] = asTenant($this->itbt, fn () => [
        userWithRole('technician', ['email' => 'tech1@itbtthai.com']),
        userWithRole('technician', ['email' => 'tech2@itbtthai.com']),
        userWithRole('helpdesk', ['email' => 'desk@itbtthai.com']),
    ]);
});

function linkedRows(User $main): ?User
{
    return User::where('login_user_id', $main->id)->first();
}

it('shares every technician of one company with another in one step', function () {
    $this->actingAs($this->superadmin)->post('/platform/staff-pools', [
        'from_tenant_id' => $this->itbt->id, 'to_tenant_id' => $this->datacom->id, 'roles' => ['technician'],
    ])->assertSessionHasNoErrors();

    asTenant($this->datacom, function () {
        expect(linkedRows($this->tech1)?->is_active)->toBeTrue()
            ->and(linkedRows($this->tech2)?->is_active)->toBeTrue()
            ->and(linkedRows($this->tech1)->hasRole('technician'))->toBeTrue()
            ->and(linkedRows($this->helpdesk))->toBeNull();
    });

    // the technician switches to Datacom and can be assigned work there
    $this->actingAs($this->tech1)->post("/switch-company/{$this->datacom->ulid}")->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs(asTenant($this->datacom, fn () => linkedRows($this->tech1)));

    $this->actingAs($this->superadmin)->get('/platform/linked-staff')
        ->assertInertia(fn (Assert $page) => $page->where('pools.0.from', 'Default')->where('pools.0.to', 'Datacom')
            ->where('pools.0.role_labels', ['ช่างเทคนิค']));
});

it('adds technicians who join later and follows their own company', function () {
    StaffPool::create(['from_tenant_id' => $this->itbt->id, 'to_tenant_id' => $this->datacom->id, 'roles' => ['technician']]);
    $this->artisan('platform:sync-staff-pools')->assertSuccessful();
    expect(asTenant($this->datacom, fn () => linkedRows($this->tech1)?->is_active))->toBeTrue();
    $admin = asTenant($this->itbt, fn () => userWithRole('admin_company'));

    // a new technician added through the user form joins at once
    $this->actingAs($admin)->post('/users', [
        'name' => 'ช่างใหม่', 'email' => 'new@itbtthai.com', 'password' => 'Secret-123', 'password_confirmation' => 'Secret-123', 'role' => 'technician',
    ])->assertSessionHasNoErrors();
    $new = asTenant($this->itbt, fn () => User::where('email', 'new@itbtthai.com')->sole());
    expect(asTenant($this->datacom, fn () => linkedRows($new)?->is_active))->toBeTrue();

    // deactivated in their own company: off in Datacom too (by the hourly sync as well)
    asTenant($this->itbt, fn () => $this->tech1->update(['is_active' => false]));
    $this->artisan('platform:sync-staff-pools')->assertSuccessful();
    asTenant($this->datacom, function () {
        expect(linkedRows($this->tech1)?->is_active)->toBeFalse()
            ->and(linkedRows($this->tech2)?->is_active)->toBeTrue();
    });
});

it('switches a pool off and on, changes its roles and ends it', function () {
    $this->actingAs($this->superadmin)->post('/platform/staff-pools', [
        'from_tenant_id' => $this->itbt->id, 'to_tenant_id' => $this->datacom->id, 'roles' => ['technician'],
    ]);
    $pool = StaffPool::sole();

    $this->actingAs($this->superadmin)->put("/platform/staff-pools/{$pool->id}", ['roles' => ['technician'], 'is_active' => false]);
    expect(asTenant($this->datacom, fn () => linkedRows($this->tech1)->is_active))->toBeFalse();

    $this->actingAs($this->superadmin)->put("/platform/staff-pools/{$pool->id}", ['roles' => ['technician', 'helpdesk'], 'is_active' => true]);
    asTenant($this->datacom, function () {
        expect(linkedRows($this->tech1)->is_active)->toBeTrue()
            ->and(linkedRows($this->helpdesk)?->hasRole('helpdesk'))->toBeTrue();
    });

    $this->actingAs($this->superadmin)->delete("/platform/staff-pools/{$pool->id}")->assertSessionHasNoErrors();
    expect(StaffPool::count())->toBe(0);
    // accounts are kept (with their work), switched off
    asTenant($this->datacom, function () {
        expect(User::whereNotNull('login_user_id')->count())->toBe(3)
            ->and(User::whereNotNull('login_user_id')->where('is_active', true)->count())->toBe(0);
    });
});

it('validates a pool', function () {
    $post = fn (array $data) => $this->actingAs($this->superadmin)->post('/platform/staff-pools', [
        'from_tenant_id' => $this->itbt->id, 'to_tenant_id' => $this->datacom->id, 'roles' => ['technician'], ...$data,
    ]);

    $post(['to_tenant_id' => $this->itbt->id])->assertSessionHasErrors('to_tenant_id');
    $post(['roles' => []])->assertSessionHasErrors('roles');
    $post(['roles' => ['customer_it']])->assertSessionHasErrors('roles.0');
    $post(['to_tenant_id' => $this->superadmin->tenant_id])->assertSessionHasErrors('to_tenant_id');
    $post([])->assertSessionHasNoErrors();
    $post([])->assertSessionHasErrors('to_tenant_id'); // the pair exists already
});

it('is only for the platform', function () {
    $companyAdmin = asTenant($this->itbt, fn () => userWithRole('admin_company'));

    $this->actingAs($companyAdmin)->post('/platform/staff-pools', [
        'from_tenant_id' => $this->itbt->id, 'to_tenant_id' => $this->datacom->id, 'roles' => ['technician'],
    ])->assertForbidden();
    expect(StaffPool::count())->toBe(0);
});
