<?php

use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('adds, edits and removes branches', function () {
    $this->actingAs($this->admin)->get('/branches/create')->assertOk();
    $this->actingAs($this->admin)->post('/branches', ['code' => 'bkk', 'name' => ' สำนักงานใหญ่ ', 'address' => '1 ถนนสีลม', 'province' => 'กรุงเทพมหานคร'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/branches');

    $branch = Branch::sole();
    expect($branch->only(['code', 'name', 'address', 'province']))
        ->toBe(['code' => 'BKK', 'name' => 'สำนักงานใหญ่', 'address' => '1 ถนนสีลม', 'province' => 'กรุงเทพมหานคร']);

    $this->actingAs($this->admin)->get("/branches/{$branch->id}/edit")->assertOk();
    $this->actingAs($this->admin)->put("/branches/{$branch->id}", ['code' => 'BKK', 'name' => 'สำนักงานใหญ่ กรุงเทพ', 'address' => '', 'province' => ''])
        ->assertSessionHasNoErrors();
    expect($branch->fresh()->only(['name', 'address', 'province']))->toBe(['name' => 'สำนักงานใหญ่ กรุงเทพ', 'address' => null, 'province' => null]);

    $this->actingAs($this->admin)->delete("/branches/{$branch->id}")->assertSessionHasNoErrors();
    expect(Branch::count())->toBe(0)
        ->and(Branch::withTrashed()->count())->toBe(1);
});

it('validates the code and keeps it unique among live branches', function () {
    Branch::create(['code' => 'CNX', 'name' => 'เชียงใหม่']);

    $this->actingAs($this->admin)->post('/branches', ['code' => 'CNX', 'name' => 'ซ้ำ'])->assertSessionHasErrors('code');
    $this->actingAs($this->admin)->post('/branches', ['code' => 'ไทย', 'name' => 'x'])->assertSessionHasErrors('code');
    $this->actingAs($this->admin)->post('/branches', ['code' => '', 'name' => ''])->assertSessionHasErrors(['code', 'name']);

    // a deleted branch frees its code
    Branch::where('code', 'CNX')->first()->delete();
    $this->actingAs($this->admin)->post('/branches', ['code' => 'CNX', 'name' => 'เชียงใหม่ ใหม่'])->assertSessionHasNoErrors();
});

it('lists branches with search, filter, sort and pages', function () {
    Branch::create(['code' => 'BKK', 'name' => 'กรุงเทพ', 'province' => 'กรุงเทพมหานคร']);
    Branch::create(['code' => 'CNX', 'name' => 'เชียงใหม่', 'province' => 'เชียงใหม่']);
    Branch::create(['code' => 'KKC', 'name' => 'ขอนแก่น', 'province' => 'ขอนแก่น']);

    $this->actingAs($this->admin)->get('/branches?sort=code&direction=desc')
        ->assertInertia(fn (Assert $page) => $page->component('Tenancy/Branches/Index')
            ->where('branches.data.0.code', 'KKC')
            ->where('branches.total', 3)
            ->where('provinces', ['กรุงเทพมหานคร', 'ขอนแก่น', 'เชียงใหม่'])
            ->where('can', ['create' => true, 'update' => true, 'delete' => true]));

    $this->actingAs($this->admin)->get('/branches?search=cnx')
        ->assertInertia(fn (Assert $page) => $page->where('branches.total', 1)->where('branches.data.0.code', 'CNX'));
    $this->actingAs($this->admin)->get('/branches?province=ขอนแก่น')
        ->assertInertia(fn (Assert $page) => $page->where('branches.total', 1)->where('branches.data.0.code', 'KKC'));
});

it('does not delete a branch that still has users, assets or tickets', function () {
    $branch = Branch::create(['code' => 'BKK', 'name' => 'กรุงเทพ']);
    $user = userWithRole('helpdesk', ['branch_id' => $branch->id]);

    $this->actingAs($this->admin)->delete("/branches/{$branch->id}")->assertSessionHasErrors('branch');
    expect(Branch::count())->toBe(1);

    $user->delete();
    $this->actingAs($this->admin)->delete("/branches/{$branch->id}")->assertSessionHasNoErrors();
});

it('needs branches.view to look and branches.manage to change', function () {
    $branch = Branch::create(['code' => 'BKK', 'name' => 'กรุงเทพ']);
    $helpdesk = userWithRole('helpdesk');

    $this->actingAs($helpdesk)->get('/branches')->assertForbidden();

    grantTo('helpdesk', ['branches.view']);
    $this->actingAs($helpdesk)->get('/branches')
        ->assertInertia(fn (Assert $page) => $page->where('can', ['create' => false, 'update' => false, 'delete' => false]));
    $this->actingAs($helpdesk)->get('/branches/create')->assertForbidden();
    $this->actingAs($helpdesk)->post('/branches', ['code' => 'X', 'name' => 'x'])->assertForbidden();
    $this->actingAs($helpdesk)->put("/branches/{$branch->id}", ['code' => 'BKK', 'name' => 'x'])->assertForbidden();
    $this->actingAs($helpdesk)->delete("/branches/{$branch->id}")->assertForbidden();

    grantTo('helpdesk', ['branches.manage']);
    $this->actingAs($helpdesk)->post('/branches', ['code' => 'CNX', 'name' => 'เชียงใหม่'])->assertSessionHasNoErrors();
});

it('shows a user limited to their branch only that branch', function () {
    $mine = Branch::create(['code' => 'BKK', 'name' => 'กรุงเทพ']);
    $other = Branch::create(['code' => 'CNX', 'name' => 'เชียงใหม่']);
    grantTo('helpdesk', ['branches.view', 'branches.manage'], PermissionCatalog::SCOPE_BRANCH);
    $helpdesk = userWithRole('helpdesk', ['branch_id' => $mine->id]);

    $this->actingAs($helpdesk)->get('/branches')
        ->assertInertia(fn (Assert $page) => $page->where('branches.total', 1)->where('branches.data.0.code', 'BKK'));
    $this->actingAs($helpdesk)->put("/branches/{$other->id}", ['code' => 'CNX', 'name' => 'x'])->assertForbidden();
    $this->actingAs($helpdesk)->put("/branches/{$mine->id}", ['code' => 'BKK', 'name' => 'กรุงเทพ 2'])->assertSessionHasNoErrors();
});

it('keeps branches per tenant', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, fn () => Branch::create(['code' => 'BKK', 'name' => 'ของบริษัทอื่น']));

    // the same code is free in this tenant
    $this->actingAs($this->admin)->post('/branches', ['code' => 'BKK', 'name' => 'ของเรา'])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/branches')
        ->assertInertia(fn (Assert $page) => $page->where('branches.total', 1)->where('branches.data.0.name', 'ของเรา'));
    $this->actingAs($this->admin)->get("/branches/{$theirs->id}/edit")->assertNotFound();
    $this->actingAs($this->admin)->put("/branches/{$theirs->id}", ['code' => 'BKK', 'name' => 'x'])->assertNotFound();
    $this->actingAs($this->admin)->delete("/branches/{$theirs->id}")->assertNotFound();

    expect(asTenant($other, fn () => Branch::sole()->name))->toBe('ของบริษัทอื่น');
});
