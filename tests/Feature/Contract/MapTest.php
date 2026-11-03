<?php

use App\Modules\Contract\Models\CustomerSite;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The map tab of the home page: branches and customer sites with coordinates, each site coloured by its customer's
 * open tickets; filters by customer and province; staff only; one company's places only.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company');
    $this->acme = createCustomer(['name' => 'Acme']);
    $this->beta = createCustomer(['name' => 'Beta']);

    Branch::create(['code' => 'HQ', 'name' => 'สำนักงานใหญ่', 'lat' => 13.7563, 'lng' => 100.5018]);
    Branch::create(['code' => 'CNX', 'name' => 'เชียงใหม่']); // not placed
    CustomerSite::create(['customer_id' => $this->acme->id, 'name' => 'Acme HQ', 'province' => 'กรุงเทพมหานคร', 'lat' => 13.7, 'lng' => 100.5]);
    CustomerSite::create(['customer_id' => $this->acme->id, 'name' => 'Acme DC', 'province' => 'ชลบุรี', 'lat' => 13.36, 'lng' => 100.98]);
    CustomerSite::create(['customer_id' => $this->beta->id, 'name' => 'Beta Office', 'province' => 'ชลบุรี', 'lat' => 13.1, 'lng' => 100.9]);
    CustomerSite::create(['customer_id' => $this->beta->id, 'name' => 'Beta Store']); // not placed
});

it('shows the placed branches and sites, each site with its customer\'s open and overdue tickets', function () {
    $desk = userWithRole('helpdesk');
    $late = openTicket($desk, ['customer_id' => $this->acme->id]);
    openTicket($desk, ['customer_id' => $this->acme->id]);
    $late->forceFill(['resolve_due_at' => now()->subHour()])->save();
    $done = openTicket($desk, ['customer_id' => $this->beta->id]);
    $done->forceFill(['status' => Ticket::STATUS_CLOSED])->save();

    // the overview tab does not load the map
    $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'overview')->where('map', null)->where('can.map', true));

    $this->actingAs($this->admin)->get('/dashboard?tab=map')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('tab', 'map')
        ->where('map.showTickets', true)
        ->where('map.unplaced', 1)
        ->where('map.provinces', ['กรุงเทพมหานคร', 'ชลบุรี'])
        ->has('map.branches', 1)
        ->where('map.branches.0.name', 'สำนักงานใหญ่')
        ->where('map.branches.0.lat', 13.7563)
        ->has('map.sites', 3)
        ->where('map.sites', fn ($sites) => collect($sites)->mapWithKeys(fn ($site) => [$site['name'] => $site['tickets']])->all() === [
            'Acme DC' => ['open' => 2, 'overdue' => 1],
            'Acme HQ' => ['open' => 2, 'overdue' => 1],
            'Beta Office' => ['open' => 0, 'overdue' => 0],
        ]));
});

it('narrows the map to a customer (no branches) or a province', function () {
    $this->actingAs($this->admin)->get("/dashboard?tab=map&customer_id={$this->beta->id}")->assertInertia(fn (Assert $page) => $page
        ->has('map.branches', 0)
        ->has('map.sites', 1)->where('map.sites.0.name', 'Beta Office')
        ->where('map.unplaced', 1));

    $this->actingAs($this->admin)->get('/dashboard?tab=map&province=ชลบุรี')->assertInertia(fn (Assert $page) => $page
        ->has('map.branches', 1)
        ->where('map.sites', fn ($sites) => collect($sites)->pluck('name')->all() === ['Acme DC', 'Beta Office']));
});

it('keeps customer accounts out and shows only the own company\'s places', function () {
    // a customer account has no map tab
    $this->actingAs(userWithRole('customer_it', ['customer_id' => $this->acme->id]))->get('/dashboard?tab=map')
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'overview')->where('map', null)->where('can.map', false));

    asTenant(createTenant('other'), function () {
        Branch::create(['code' => 'X', 'name' => 'Their branch', 'lat' => 14, 'lng' => 100]);
        CustomerSite::create(['customer_id' => createCustomer(['name' => 'Theirs'])->id, 'name' => 'Their site', 'lat' => 14, 'lng' => 100]);
    });

    $this->actingAs($this->admin)->get('/dashboard?tab=map')->assertInertia(fn (Assert $page) => $page
        ->has('map.branches', 1)
        ->where('map.sites', fn ($sites) => ! collect($sites)->pluck('name')->contains('Their site')));
});
