<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Activity;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Here']);
    $this->other = createTenant('other');
});

it('lists only users of the admin tenant', function () {
    userWithRole('technician', ['name' => 'Tech Here']);
    userWithRole('technician', ['name' => 'Tech There'], $this->other);

    $this->actingAs($this->admin)->get('/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Identity/Users/Index')
            ->where('users.total', 2)
            ->where('users.data', fn ($users) => collect($users)->pluck('name')->sort()->values()->all() === ['Admin Here', 'Tech Here']));
});

it('searches and filters users on the server', function () {
    userWithRole('technician', ['name' => 'Somchai', 'employee_code' => 'EMP-001']);
    userWithRole('helpdesk', ['name' => 'Somsri']);

    $this->actingAs($this->admin)->get('/users?search=EMP-001')
        ->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.name', 'Somchai'));

    $this->actingAs($this->admin)->get('/users?role=helpdesk')
        ->assertInertia(fn (Assert $page) => $page->where('users.total', 1)->where('users.data.0.name', 'Somsri'));
});

it('forbids user management without user.view', function () {
    $technician = userWithRole('technician');

    $this->actingAs($technician)->get('/users')->assertForbidden();
    $this->actingAs($technician)->get('/roles')->assertForbidden();
});

it('lets the admin create a user with a role and logs who did it', function () {
    $branch = Branch::create(['code' => 'BKK', 'name' => 'กรุงเทพ']);

    $this->actingAs($this->admin)->post('/users', [
        'name' => 'New Tech',
        'email' => 'new.tech@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
        'branch_id' => $branch->id,
        'service_lines' => ['network', 'pc'],
        'is_active' => true,
        'role' => 'technician',
    ])->assertRedirect('/users')->assertSessionHasNoErrors();

    $user = User::where('email', 'new.tech@example.com')->first();
    expect($user->tenant_id)->toBe($this->tenant->id)
        ->and($user->branch_id)->toBe($branch->id)
        ->and($user->service_lines)->toBe(['network', 'pc'])
        ->and($user->hasRole('technician'))->toBeTrue();

    $log = Activity::where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->first();
    expect($log->properties['actor']['name'])->toBe('Admin Here');
});

it('rejects an e-mail that another tenant already uses', function () {
    userWithRole('user', ['email' => 'taken@example.com'], $this->other);

    $this->actingAs($this->admin)->post('/users', [
        'name' => 'Dup', 'email' => 'taken@example.com',
        'password' => 'password-123', 'password_confirmation' => 'password-123', 'role' => 'user',
    ])->assertSessionHasErrors('email');
});

it('rejects a branch or role of another tenant', function () {
    $foreignBranch = asTenant($this->other, fn () => Branch::create(['code' => 'X', 'name' => 'Foreign']));
    asTenant($this->other, fn () => Role::create(['name' => 'foreign_role', 'label' => 'Foreign', 'guard_name' => 'web']));

    $this->actingAs($this->admin)->post('/users', [
        'name' => 'X', 'email' => 'x@example.com',
        'password' => 'password-123', 'password_confirmation' => 'password-123',
        'branch_id' => $foreignBranch->id, 'role' => 'foreign_role',
    ])->assertSessionHasErrors(['branch_id', 'role']);
});

it('returns 404 for a user of another tenant', function () {
    $foreigner = userWithRole('user', [], $this->other);

    $this->actingAs($this->admin)->get("/users/{$foreigner->id}/edit")->assertNotFound();
});

it('does not let an admin deactivate themselves', function () {
    $this->actingAs($this->admin)->put("/users/{$this->admin->id}", [
        'name' => $this->admin->name, 'email' => $this->admin->email,
        'is_active' => false, 'role' => 'admin_company',
    ])->assertSessionHasErrors('is_active');
});

it('lets the admin create a role and change its permissions', function () {
    $this->actingAs($this->admin)->post('/roles', [
        'name' => 'auditor', 'label' => 'ผู้ตรวจสอบ', 'permissions' => ['asset.view', 'report.view'],
    ])->assertRedirect('/roles')->assertSessionHasNoErrors();

    $role = Role::findByName('auditor');
    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe(['asset.view', 'report.view'])
        ->and(asTenant($this->other, fn () => Role::where('name', 'auditor')->exists()))->toBeFalse();
});

it('does not allow platform permissions in a tenant role', function () {
    $this->actingAs($this->admin)->post('/roles', [
        'name' => 'sneaky', 'label' => 'Sneaky', 'permissions' => ['platform.impersonate'],
    ])->assertSessionHasErrors('permissions.0');
});

it('keeps the name of a system role', function () {
    $role = Role::findByName('technician');

    $this->actingAs($this->admin)->put("/roles/{$role->id}", [
        'name' => 'renamed', 'label' => 'ช่าง', 'permissions' => ['ticket.view'],
    ])->assertSessionHasNoErrors();

    expect($role->fresh()->name)->toBe('technician')
        ->and($role->fresh()->label)->toBe('ช่าง');
});
