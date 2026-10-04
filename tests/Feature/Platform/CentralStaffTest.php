<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Models\Activity;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();

    // the platform tenant with its three roles, and a user in each
    $this->superadmin = createSuperadmin();
    $this->platform = $this->superadmin->tenant;
    $this->centralHelpdesk = userWithRole(PermissionCatalog::CENTRAL_HELPDESK, ['name' => 'Central Helpdesk'], $this->platform);
    $this->centralTech = userWithRole(PermissionCatalog::CENTRAL_TECHNICIAN, ['name' => 'Central Tech'], $this->platform);

    // a customer company (the test's default tenant) with two branches and work in both
    $this->north = Branch::create(['code' => 'N', 'name' => 'North']);
    $this->south = Branch::create(['code' => 'S', 'name' => 'South']);
    $this->admin = userWithRole('admin_company');
    $this->localTech = userWithRole('technician', ['branch_id' => $this->north->id]);
    $customer = createCustomer(['name' => 'Acme']);
    $this->northAsset = createAsset(createAssetCategory(), ['customer_id' => $customer->id, 'branch_id' => $this->north->id]);
    $this->southAsset = createAsset(createAssetCategory(), ['customer_id' => $customer->id, 'branch_id' => $this->south->id]);
    $this->ticket = openTicket($this->admin, ['asset_id' => $this->southAsset->id, 'customer_id' => $customer->id]);
    app(AssignTicket::class)->handle($this->ticket, $this->localTech->id, $this->admin);

    $this->enter = fn (User $user) => $this->actingAs($user)->post("/platform/impersonation/{$this->tenant->ulid}");
});

it('seeds the platform tenant with the superadmin and the two central roles', function () {
    expect(asTenant($this->platform, fn () => Role::orderBy('name')->pluck('name')->all()))
        ->toBe(['central_helpdesk', 'central_technician', 'superadmin'])
        ->and(PermissionCatalog::platformPermissionsFor(PermissionCatalog::CENTRAL_HELPDESK))->not->toContain('platform.full_access', 'platform.tenants', 'users.manage', 'roles.manage')
        ->and(PermissionCatalog::platformPermissionsFor(PermissionCatalog::SUPERADMIN))->toContain('platform.full_access');
});

it('lets central staff enter any company and see every branch, with the menu of their role', function () {
    $this->actingAs($this->centralHelpdesk)->get('/platform/impersonation')->assertOk();
    // at home (the platform tenant) there is no company data
    $this->actingAs($this->centralHelpdesk)->get('/tickets')->assertNotFound();

    ($this->enter)($this->centralHelpdesk)->assertRedirect(route('dashboard'));

    $this->actingAs($this->centralHelpdesk)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.name', 'Default')
        ->where('impersonation.tenant.name', 'Default')
        ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('tickets.assign')
            && ! collect($permissions)->contains('users.view') && ! collect($permissions)->contains('platform.impersonate'))
        ->where('navigation', fn ($items) => collect($items)->pluck('title')->all() === [
            'หน้าหลัก', 'งานของฉัน', 'ใบงาน', 'รอบ PM', 'แผน PM', 'Checklist PM', 'เช็ค IP ว่าง', 'ทรัพย์สิน', 'เบิก / ยืม', 'หมวดทรัพย์สิน', 'พิมพ์ป้าย QR',
            'ลูกค้า', 'สัญญา MA', 'อะไหล่', 'ใบขอซื้อ', 'ค้นของบริษัทอื่น', 'ความเคลื่อนไหวสต็อก', 'รายงาน', 'สรุปรายบุคคล', 'สรุปรายโครงการ', 'ผลประเมินความพึงพอใจ', 'คู่มือ', 'วันหยุด',
        ]));

    // every branch, though the central user has none
    $this->actingAs($this->centralHelpdesk)->get('/assets')->assertInertia(fn (Assert $page) => $page->where('assets.total', 2));
    $this->actingAs($this->centralHelpdesk)->get("/assets/{$this->southAsset->ulid}")->assertOk();
    $this->actingAs($this->centralHelpdesk)->get("/tickets/{$this->ticket->ulid}")->assertOk();
    $this->actingAs($this->centralHelpdesk)->get('/reports')->assertOk();
});

it('lets central staff work but not configure the company', function () {
    ($this->enter)($this->centralHelpdesk);

    // daily work: dispatch and comment
    $this->actingAs($this->centralHelpdesk)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => null])->assertSessionHasNoErrors();
    $this->actingAs($this->centralHelpdesk)->post("/tickets/{$this->ticket->ulid}/comments", ['body' => 'รับเรื่องจากส่วนกลาง'])->assertSessionHasNoErrors();
    expect($this->ticket->events()->latest('id')->first()->only(['body', 'user_name']))
        ->toBe(['body' => 'รับเรื่องจากส่วนกลาง', 'user_name' => 'Central Helpdesk']);

    // settings are closed: users, roles, categories, checklists, holidays, parts, imports
    $this->actingAs($this->centralHelpdesk)->get('/users')->assertForbidden();
    $this->actingAs($this->centralHelpdesk)->post('/users', ['name' => 'X', 'email' => 'x@x.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'role' => 'user'])->assertForbidden();
    $this->actingAs($this->centralHelpdesk)->get('/roles')->assertForbidden();
    $this->actingAs($this->centralHelpdesk)->post('/asset-categories', ['name' => 'X', 'code_prefix' => 'X'])->assertForbidden();
    $this->actingAs($this->centralHelpdesk)->post('/holidays', ['date' => '2026-12-25', 'name' => 'X'])->assertForbidden();
    $this->actingAs($this->centralHelpdesk)->post('/parts', ['code' => 'X', 'name' => 'X', 'unit' => 'pcs'])->assertForbidden();
    $this->actingAs($this->centralHelpdesk)->get('/assets/imports')->assertForbidden();
    $this->actingAs($this->centralHelpdesk)->delete("/assets/{$this->northAsset->ulid}")->assertForbidden();
    // nor the platform settings of the company
    $this->actingAs($this->centralHelpdesk)->get("/platform/tenants/{$this->tenant->ulid}/modules")->assertForbidden();

    expect(User::where('email', 'x@x.test')->exists())->toBeFalse();
});

it('lets a central technician work on tickets without being the assignee', function () {
    $this->ticket->refresh();
    ($this->enter)($this->centralTech);
    $url = "/tickets/{$this->ticket->ulid}";

    $this->actingAs($this->centralTech)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('actions', ['start'])->where('assignees', null)->where('can.update', true));
    $this->actingAs($this->centralTech)->post("{$url}/move", ['action' => 'start'])->assertSessionHasNoErrors();
    $this->actingAs($this->centralTech)->post("{$url}/move", ['action' => 'resolve'])->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_RESOLVED);

    // a technician, central or not, does not dispatch
    $this->actingAs($this->centralTech)->post("{$url}/assign", ['assignee_id' => $this->localTech->id])->assertForbidden();
    $this->actingAs($this->centralTech)->get('/reports')->assertForbidden();
    $this->actingAs($this->centralTech)->get('/users')->assertForbidden();

    // takes a part for the job, like a local technician
    $part = createPart(stock: 2);
    $this->actingAs($this->centralTech)->post("{$url}/parts", ['part_id' => $part->id, 'quantity' => 1])->assertSessionHasNoErrors();
    expect($part->fresh()->qty_on_hand)->toBe(1);
    $this->actingAs($this->centralTech)->post("/parts/{$part->id}/movements", ['type' => 'receive', 'quantity' => 5])->assertForbidden();

    // and closes the resolved job (tickets.close)
    $this->actingAs($this->centralTech)->post("{$url}/move", ['action' => 'approve'])->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_CLOSED);
});

it('logs what central staff do in the company with their real name, and leaves when they stop', function () {
    ($this->enter)($this->centralTech);
    $this->actingAs($this->centralTech)->get('/tickets');

    $logs = Activity::orderBy('id')->get();
    expect($logs->pluck('description')->all())->toContain('เริ่มเข้าดูในนามบริษัท', 'GET /tickets')
        ->and($logs->last()->properties['actor']['name'])->toBe('Central Tech');

    $this->actingAs($this->centralTech)->delete('/platform/impersonation');
    $this->actingAs($this->centralTech)->get('/tickets')->assertNotFound();
    $this->actingAs($this->centralTech)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('impersonation', null));
});

it('keeps the superadmin all-powerful inside a company and company staff out of others', function () {
    ($this->enter)($this->superadmin);
    $this->actingAs($this->superadmin)->get('/users')->assertOk();
    $this->actingAs($this->superadmin)->get('/roles')->assertOk();
    $this->actingAs($this->superadmin)->delete('/platform/impersonation');

    // a company's own helpdesk cannot enter anything, whatever branch they are in
    $other = createTenant('other');
    $helpdesk = userWithRole('helpdesk', ['branch_id' => $this->north->id]);
    $this->actingAs($helpdesk)->post("/platform/impersonation/{$other->ulid}")->assertForbidden();
    $this->actingAs($helpdesk)->get('/platform/impersonation')->assertForbidden();
    // in its own company it sees every branch (helpdesk grants have scope all), but no further
    $this->actingAs($helpdesk)->get('/assets')->assertInertia(fn (Assert $page) => $page->where('assets.total', 2));
    setRoleScope('helpdesk', 'branch', ['assets.view']);
    $this->actingAs($helpdesk)->get('/assets')->assertInertia(fn (Assert $page) => $page->where('assets.total', 1));
    $this->actingAs($helpdesk)->get("/assets/{$this->southAsset->ulid}")->assertForbidden();
});
