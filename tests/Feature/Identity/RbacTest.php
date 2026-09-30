<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Branch;

it('seeds the default roles into every new tenant', function () {
    expect(Role::orderBy('name')->pluck('name')->all())
        ->toBe(['admin_company', 'customer', 'helpdesk', 'technician', 'user']);

    $other = createTenant('other');
    expect(asTenant($other, fn () => Role::count()))->toBe(5);
});

it('does not let a technician of branch A open data of branch B', function () {
    $branchA = Branch::create(['code' => 'A', 'name' => 'สาขา A']);
    $branchB = Branch::create(['code' => 'B', 'name' => 'สาขา B']);

    $technician = userWithRole('technician', ['branch_id' => $branchA->id]);
    $colleagueInB = userWithRole('technician', ['branch_id' => $branchB->id]);

    expect($technician->can('view', $branchA))->toBeTrue()
        ->and($technician->can('view', $branchB))->toBeFalse()
        ->and($technician->can('update', $branchB))->toBeFalse()
        // records with a branch_id column follow the same rule
        ->and($technician->can('view', $colleagueInB))->toBeFalse();

    // helpdesk works in its own branch too; only the company admin sees every branch ...
    $helpdesk = userWithRole('helpdesk', ['branch_id' => $branchA->id]);
    expect($helpdesk->can('view', $branchA))->toBeTrue()
        ->and($helpdesk->can('view', $branchB))->toBeFalse()
        ->and(userWithRole('admin_company', ['branch_id' => $branchA->id])->can('view', $branchB))->toBeTrue();

    // ... unless the company gives the role branch.all (a head-office dispatcher)
    allowAllBranches('helpdesk');
    expect($helpdesk->fresh()->can('view', $branchB))->toBeTrue();
});

it('does not give helpdesk ticket.approve', function () {
    $helpdesk = userWithRole('helpdesk');
    $admin = userWithRole('admin_company');

    expect($helpdesk->can('ticket.approve'))->toBeFalse()
        ->and($helpdesk->can('ticket.assign'))->toBeTrue()
        ->and($admin->can('ticket.approve'))->toBeTrue();
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
    asTenant($other, fn () => Role::findByName('technician')->givePermissionTo('ticket.approve'));

    // ... which must not change technicians of this tenant
    $technicianHere = userWithRole('technician');
    expect($technicianHere->can('ticket.approve'))->toBeFalse()
        ->and(asTenant($other, fn () => $technicianThere->fresh()->can('ticket.approve')))->toBeTrue()
        ->and(Role::where('tenant_id', $other->id)->count())->toBe(0);
});
