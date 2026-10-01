<?php

use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Tenancy\Models\Branch;
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

    // a customer account: its own tickets only, nothing about the company
    $client = userWithRole('customer', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tickets.open', 1)
        ->where('contracts', null)->where('parts', null)->where('surveys', null));
});

it('leaves out the cards of modules that are switched off', function () {
    foreach (['service', 'maintenance', 'inventory', 'contract'] as $module) {
        Feature::for($this->tenant)->deactivate(Modules::feature($module));
    }

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tickets', null)->where('pm', null)->where('parts', null)->where('contracts', null)
        ->where('can.createTicket', false));
});

it('gives the platform staff the shortcuts only', function () {
    $superadmin = createSuperadmin();

    $this->actingAs($superadmin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tickets', null)->where('pm', null)->where('tenant.is_platform', true));
});
