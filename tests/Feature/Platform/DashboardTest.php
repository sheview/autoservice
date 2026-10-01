<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Notification::fake();
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Here']);
    $this->north = Branch::create(['code' => 'N', 'name' => 'North']);
    $this->tech = userWithRole('technician', ['branch_id' => $this->north->id]);
    $this->customer = createCustomer();
});

it('sends visitors of the root to sign in, and signed-in users to the dashboard', function () {
    $this->get('/')->assertRedirect('/login');
    $this->actingAs($this->admin)->get('/')->assertRedirect('/dashboard');
});

it('shows what needs attention, within what the user may see', function () {
    $asset = createAsset(createAssetCategory(), ['customer_id' => $this->customer->id, 'branch_id' => $this->north->id]);
    $mine = openTicket($this->admin, ['title' => 'Mine', 'asset_id' => $asset->id, 'customer_id' => $this->customer->id]);
    app(AssignTicket::class)->handle($mine, $this->tech->id, $this->admin);
    openTicket($this->admin, ['title' => 'Nobody yet']);
    // in another branch: the technician does not see it
    $south = Branch::create(['code' => 'S', 'name' => 'South']);
    openTicket($this->admin, ['title' => 'South job', 'asset_id' => createAsset(createAssetCategory(), ['branch_id' => $south->id])->id]);
    createPart(['min_qty' => 5], stock: 1);

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('tickets.open', 3)
        ->where('tickets.unassigned', 2)
        ->where('tickets.mine', 0)
        ->has('tickets.recent', 3)
        ->where('pm', ['overdue' => 0, 'this_month' => 0, 'mine' => 0])
        ->where('contracts.expiring', 0)
        ->where('parts', ['low' => 1, 'out' => 0])
        ->where('surveys.sent', 0)
        ->where('can', ['createTicket' => true, 'reports' => true]));

    // a technician: their own branch, their own work first, no office figures
    $this->actingAs($this->tech)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tickets.mine', 1)
        ->where('tickets.recent', fn ($recent) => collect($recent)->pluck('title')->all() === ['Mine'])
        ->where('contracts.expiring', 0)
        ->where('surveys', null)
        ->where('can.reports', false));

    // a customer account: its own tickets and contracts only, nothing about the company's stock
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tickets.open', 1)
        ->where('contracts.expiring', 0)->where('parts', null)->where('surveys.sent', 0));
});

it('leaves out the cards of modules that are switched off', function () {
    foreach (['service', 'maintenance', 'inventory', 'contract'] as $module) {
        Feature::for($this->tenant)->deactivate(Modules::feature($module));
    }

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tickets', null)->where('pm', null)->where('parts', null)->where('contracts', null)
        ->where('can.createTicket', false));
});

it('gives the platform its customer companies, and the ones to renew first', function () {
    $this->travelTo('2026-10-01 10:00');
    $superadmin = createSuperadmin();
    // the test's default company has no dates (unlimited); a few more:
    Tenant::create(['name' => 'Soon', 'slug' => 'soon', 'subdomain' => 'soon', 'subscription_ends_on' => '2026-10-10']);
    Tenant::create(['name' => 'Later', 'slug' => 'later', 'subdomain' => 'later', 'subscription_ends_on' => '2026-11-20']);
    Tenant::create(['name' => 'Far', 'slug' => 'far', 'subdomain' => 'far', 'subscription_ends_on' => '2027-09-30']);
    Tenant::create(['name' => 'Gone', 'slug' => 'gone', 'subdomain' => 'gone', 'subscription_ends_on' => '2026-09-20']);
    Tenant::create(['name' => 'Long gone', 'slug' => 'longgone', 'subdomain' => 'longgone', 'subscription_ends_on' => '2026-06-01', 'status' => 'suspended']);

    $this->actingAs($superadmin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tickets', null)->where('pm', null)->where('tenant.is_platform', true)
        ->where('platform.counts', ['total' => 6, 'active' => 3, 'expiring' => 1, 'grace' => 1, 'locked' => 1, 'not_started' => 0, 'suspended' => 1])
        // ended longest ago first, then the soonest to end; "Far" is not due yet
        ->where('platform.renewals', fn ($rows) => collect($rows)->pluck('name')->all() === ['Long gone', 'Gone', 'Soon', 'Later'])
        ->where('platform.renewals.2.subscription.days_left', 9)
        ->where('platform.can.manage', true)
        ->has('platform.newest', 6));

    // inside a company, or for company users, there is no platform overview
    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('platform', null));
    $this->actingAs($superadmin)->post("/platform/impersonation/{$this->tenant->ulid}");
    $this->actingAs($superadmin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('platform', null));
});

it('needs dashboard.view, and sends those without it to the first page of their menu', function () {
    $role = Role::create(['name' => 'stock_clerk', 'label' => 'Stock', 'guard_name' => 'web']);
    $role->givePermissionTo('parts.view');
    $clerk = User::factory()->create();
    $clerk->assignRole('stock_clerk');

    $this->actingAs($clerk)->get('/dashboard')->assertRedirect('/parts');

    $role->revokePermissionTo('parts.view');
    $this->actingAs($clerk->fresh())->get('/dashboard')->assertForbidden();
});

it('counts only the own work of a technician (scope own)', function () {
    $other = userWithRole('technician', ['branch_id' => $this->north->id]);
    $mine = openTicket($this->admin, ['title' => 'Mine', 'customer_id' => $this->customer->id]);
    app(AssignTicket::class)->handle($mine, $this->tech->id, $this->admin);
    $theirs = openTicket($this->admin, ['title' => 'Theirs']);
    app(AssignTicket::class)->handle($theirs, $other->id, $this->admin);

    $this->actingAs($this->tech)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tickets.mine', 1)
        ->where('tickets.recent', fn ($recent) => collect($recent)->pluck('title')->all() === ['Mine'])
        ->where('pm', ['overdue' => 0, 'this_month' => 0, 'mine' => 0])
        // surveys of their own tickets only cannot be added up: no card
        ->where('surveys', null));
});
