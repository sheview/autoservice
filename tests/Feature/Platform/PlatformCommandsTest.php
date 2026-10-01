<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission;

it('installs the platform tenant and its first superadmin', function () {
    $this->artisan('platform:install', ['--name' => 'Owner', '--email' => 'Owner@Example.com', '--password' => 'password-123'])
        ->assertSuccessful();

    $platform = Tenant::where('is_platform', true)->sole();
    expect($platform->subdomain)->toBe('admin');
    asTenant($platform, function () {
        $owner = User::where('email', 'owner@example.com')->sole();
        expect($owner->hasRole(PermissionCatalog::SUPERADMIN))->toBeTrue()
            ->and(Role::orderBy('name')->pluck('name')->all())->toBe(['central_helpdesk', 'central_technician', 'superadmin']);
    });

    // run again: the tenant is kept, a second superadmin is added, a used e-mail is refused
    $this->artisan('platform:install', ['--name' => 'Second', '--email' => 'second@example.com', '--password' => 'password-123'])->assertSuccessful();
    $this->artisan('platform:install', ['--name' => 'Again', '--email' => 'owner@example.com', '--password' => 'password-123'])->assertFailed();
    $this->artisan('platform:install', ['--name' => 'Weak', '--email' => 'weak@example.com', '--password' => '123'])->assertFailed();

    expect(Tenant::where('is_platform', true)->count())->toBe(1)
        ->and(asTenant($platform, fn () => User::count()))->toBe(2);
});

it('brings permissions up to date after a deploy without undoing company changes', function () {
    // a company that changed its technician role, and lost a new admin permission
    Role::findByName('technician')->revokePermissionTo('labels.print');
    Role::findByName('admin_company')->revokePermissionTo('surveys.view');
    Permission::where('name', 'reports.view')->delete();

    $this->artisan('platform:sync-permissions')->assertSuccessful();

    expect(Permission::where('name', 'reports.view')->exists())->toBeTrue()
        ->and(Role::findByName('admin_company')->hasPermissionTo('surveys.view'))->toBeTrue()
        ->and(Role::findByName('technician')->hasPermissionTo('labels.print'))->toBeFalse();

    // --defaults puts the default roles back exactly as the catalog says
    Role::findByName('technician')->givePermissionTo('reports.view');
    $this->artisan('platform:sync-permissions', ['--defaults' => true])->assertSuccessful();
    expect(Role::findByName('technician')->fresh()->hasPermissionTo('labels.print'))->toBeTrue()
        ->and(Role::findByName('technician')->fresh()->hasPermissionTo('reports.view'))->toBeFalse();
});

it('sends no scheduled e-mails for a company that is locked out', function () {
    Bus::fake();
    $this->tenant->update(['subscription_starts_on' => '2024-01-01', 'subscription_ends_on' => '2024-12-31']);
    $open = createTenant('open');

    $this->artisan('inventory:notify-low-stock')->expectsOutputToContain('Queued for 1 tenant(s).');
    expect(app(Modules::class)->enabled('inventory', $open))->toBeTrue();
});
