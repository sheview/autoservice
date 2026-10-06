<?php

use App\Modules\Identity\Actions\SyncRoleGrants;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Models\Activity;
use App\Modules\Platform\Support\Modules;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->technician = Role::findByName('technician');
    $this->grants = fn (string $role) => app(SyncRoleGrants::class)->grantsOf(Role::findByName($role));
});

it('seeds the default roles exactly as permissions.json says', function () {
    expect(($this->grants)('technician'))->toBe(collect(PermissionCatalog::grantsFor('technician'))->sortKeys()->all())
        ->and(($this->grants)('technician')['tickets.view'])->toBe('own')
        ->and(($this->grants)('technician')['assets.view'])->toBe('branch')
        ->and(($this->grants)('helpdesk')['tickets.view'])->toBe('all')
        ->and(($this->grants)('customer_it')['tickets.view'])->toBe('customer')
        ->and(($this->grants)('user'))->not->toHaveKey('parts.view')
        ->and(($this->grants)('admin_company'))->toHaveCount(count(PermissionCatalog::tenantPermissions()));
});

it('shows the matrix of every role to whoever manages roles only', function () {
    $this->actingAs($this->admin)->get('/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Identity/Roles/Index')
        ->has('roles', 6)
        ->where('roles.0.locked', true)
        ->where("grants.{$this->technician->id}", fn ($grants) => $grants['tickets.view'] === 'own')
        ->has('resources'));

    $this->actingAs(userWithRole('technician'))->get('/roles')->assertForbidden();
    $this->actingAs(userWithRole('helpdesk'))->put('/roles-matrix', ['matrix' => []])->assertForbidden();
});

it('saves ticks and scopes, and logs what changed', function () {
    $grants = ($this->grants)('technician');
    unset($grants['labels.print']);
    $grants['tickets.view'] = 'branch';
    $grants['reports.view'] = 'own';

    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$this->technician->id => $grants]])->assertSessionHasNoErrors();

    $after = ($this->grants)('technician');
    expect($after)->not->toHaveKey('labels.print')
        ->and($after['tickets.view'])->toBe('branch')
        ->and($after['reports.view'])->toBe('own');

    $log = Activity::where('event', 'role_grants_updated')->sole();
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['added'])->toBe(['reports.view' => 'own'])
        ->and($log->properties['removed'])->toBe(['labels.print'])
        ->and($log->properties['rescoped'])->toBe(['tickets.view' => ['own', 'branch']]);

    // takes effect at once: a technician now reaches the branch
    $tech = userWithRole('technician');
    DataScope::forget();
    expect(DataScope::of($tech, 'tickets.view'))->toBe('branch')
        ->and($tech->can('labels.print'))->toBeFalse();

    // saving the same again logs nothing
    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$this->technician->id => $after]]);
    expect(Activity::where('event', 'role_grants_updated')->count())->toBe(1);
});

it('never changes the admin role and checks permissions and scopes', function () {
    $admin = Role::findByName('admin_company');
    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$admin->id => ['tickets.view' => 'all']]])->assertSessionHasErrors('matrix');
    expect(($this->grants)('admin_company'))->toHaveCount(count(PermissionCatalog::tenantPermissions()));

    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$this->technician->id => ['platform.full_access' => 'all']]])->assertSessionHasErrors('matrix');
    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$this->technician->id => ['tickets.view' => 'customer']]])->assertSessionHasErrors('matrix');

    $customer = Role::findByName('customer_it');
    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$customer->id => ['tickets.view' => 'all']]])->assertSessionHasErrors('matrix');
    expect(($this->grants)('technician')['tickets.view'])->toBe('own');
});

it('creates a role, then gives it permissions on the matrix', function () {
    $this->actingAs($this->admin)->post('/roles', ['name' => 'store_keeper', 'label' => 'เจ้าหน้าที่คลัง'])->assertSessionHasNoErrors();
    $role = Role::findByName('store_keeper');
    expect(($this->grants)('store_keeper'))->toBe([]);

    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$role->id => ['parts.view' => 'all', 'stock-movements.create' => 'all']]]);
    expect(($this->grants)('store_keeper'))->toBe(['parts.view' => 'all', 'stock-movements.create' => 'all'])
        ->and(Activity::where('event', 'role_created')->exists())->toBeTrue();
});

it('never leaves the company without an active admin', function () {
    $update = fn ($user, array $changes) => $this->actingAs($this->admin)->put("/users/{$user->id}", [
        'name' => $user->name, 'email' => $user->email, 'role' => 'admin_company', 'is_active' => true, ...$changes,
    ]);

    // the only admin: may not lose the role (deactivating oneself is refused anyway)
    $update($this->admin, ['role' => 'helpdesk'])->assertSessionHasErrors('role');
    expect($this->admin->fresh()->hasRole('admin_company'))->toBeTrue();

    // with a second active admin, the first may step down; the second is then the last one
    $second = userWithRole('admin_company', ['name' => 'Second Admin']);
    $update($second, ['role' => 'helpdesk'])->assertSessionHasNoErrors();
    expect($second->fresh()->hasRole('helpdesk'))->toBeTrue()
        ->and(Activity::where('event', 'user_role_changed')->exists())->toBeTrue();

    $third = userWithRole('admin_company', ['name' => 'Third Admin']);
    $update($third, ['is_active' => false])->assertSessionHasNoErrors();
    $this->actingAs($third->fresh());
    $update($this->admin, ['role' => 'technician'])->assertSessionHasErrors('role');
});

it('lists permissions in the order of the menu, without modules the company does not use, keeping their grants', function () {
    expect(array_keys(PermissionCatalog::MENU))->toEqualCanonicalizing(array_values(array_diff(array_keys(PermissionCatalog::PERMISSIONS), ['platform', ...PermissionCatalog::NO_MENU])));

    $admin = userWithRole('admin_company');
    $this->actingAs($admin)->get('/roles')->assertInertia(fn (Assert $page) => $page
        ->where('resources.0.key', 'dashboard')
        ->where('resources', fn ($rows) => collect($rows)->pluck('key')->contains('room-access')
            && collect($rows)->firstWhere('key', 'room-access')['group'] === 'service'));

    // Room access switched off for the company: its permissions leave the matrix, grants stay.
    Feature::for($this->tenant)->deactivate(Modules::feature('room_access'));
    $this->actingAs($admin)->get('/roles')->assertInertia(fn (Assert $page) => $page
        ->where('resources', fn ($rows) => ! collect($rows)->pluck('key')->contains('room-access')));
    $technician = Role::findByName('technician');
    expect(app(SyncRoleGrants::class)->grantsOf($technician))->toHaveKey('room-access.view');
});

it('leaves resources whose menu was removed off the matrix, keeping their grants', function () {
    // The page sends back every grant it was given, so grants not shown survive a save.
    $this->actingAs($this->admin)->get('/roles')->assertInertia(fn (Assert $page) => $page
        ->where('resources', fn ($rows) => collect($rows)->pluck('key')->intersect(PermissionCatalog::NO_MENU)->isEmpty())
        ->where("grants.{$this->technician->id}", fn ($grants) => $grants['pm-visits.view'] === 'own'));

    $this->actingAs($this->admin)->put('/roles-matrix', ['matrix' => [$this->technician->id => ($this->grants)('technician')]])->assertSessionHasNoErrors();
    expect(($this->grants)('technician'))->toHaveKeys(['pm-visits.view', 'pm-plans.view', 'pm-checklists.view']);
});
