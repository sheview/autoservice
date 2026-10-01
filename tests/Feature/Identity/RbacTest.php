<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Branch;

it('seeds the default roles into every new tenant', function () {
    expect(Role::orderBy('name')->pluck('name')->all())
        ->toBe(['admin_company', 'customer_it', 'helpdesk', 'technician', 'user']);

    $other = createTenant('other');
    expect(asTenant($other, fn () => Role::count()))->toBe(5);
});

it('does not let a grant with scope branch open data of another branch', function () {
    $branchA = Branch::create(['code' => 'A', 'name' => 'สาขา A']);
    $branchB = Branch::create(['code' => 'B', 'name' => 'สาขา B']);

    // technicians of this company may look at branches and users, of their own branch
    grantTo('technician', ['branches.view', 'users.view'], 'branch');
    $technician = userWithRole('technician', ['branch_id' => $branchA->id]);
    $colleagueInB = userWithRole('technician', ['branch_id' => $branchB->id]);

    expect($technician->can('view', $branchA))->toBeTrue()
        ->and($technician->can('view', $branchB))->toBeFalse()
        ->and($technician->can('update', $branchA))->toBeFalse()
        // records with a branch_id column follow the same rule
        ->and($technician->can('view', $colleagueInB))->toBeFalse()
        ->and($technician->can('view', userWithRole('user')))->toBeTrue();

    // the company admin sees every branch ...
    expect(userWithRole('admin_company', ['branch_id' => $branchA->id])->can('view', $branchB))->toBeTrue();

    // ... and so does a role whose grants have scope all (a head-office dispatcher)
    allowAllBranches('technician');
    expect($technician->fresh()->can('view', $branchB))->toBeTrue()
        ->and($technician->fresh()->can('view', $colleagueInB))->toBeTrue();
});

it('lets a grant with scope own reach only the user themself', function () {
    grantTo('user', ['users.view'], 'own');
    $user = userWithRole('user');

    expect($user->can('view', $user))->toBeTrue()
        ->and($user->can('view', userWithRole('user')))->toBeFalse();
});

it('does not give helpdesk tickets.approve', function () {
    $helpdesk = userWithRole('helpdesk');
    $admin = userWithRole('admin_company');

    expect($helpdesk->can('tickets.approve'))->toBeFalse()
        ->and($helpdesk->can('tickets.assign'))->toBeTrue()
        ->and($admin->can('tickets.approve'))->toBeTrue();
});

it('never grants platform permissions to customer roles', function () {
    $admin = userWithRole('admin_company');

    expect($admin->can('platform.impersonate'))->toBeFalse()
        ->and(PermissionCatalog::permissionsFor('admin_company'))->not->toContain('platform.impersonate');
});

it('keeps roles and permissions of each tenant separate', function () {
    $other = createTenant('other');
    $technicianThere = userWithRole('technician', [], $other);

    // tenant "other" lets its technicians approve tickets ...
    asTenant($other, fn () => Role::findByName('technician')->givePermissionTo('tickets.approve'));

    // ... which must not change technicians of this tenant
    $technicianHere = userWithRole('technician');
    expect($technicianHere->can('tickets.approve'))->toBeFalse()
        ->and(asTenant($other, fn () => $technicianThere->fresh()->can('tickets.approve')))->toBeTrue()
        ->and(Role::where('tenant_id', $other->id)->count())->toBe(0);
});
