<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Service\Actions\AssignTicket;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The scopes of permissions.json end to end: a customer's IT staff (customer_it, scope customer)
 * never reaches another customer's records by URL, and a technician (tickets scope own) never
 * reaches someone else's ticket.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Help Desk']);

    $this->acme = createCustomer(['name' => 'Acme Hospital']);
    $this->globex = createCustomer(['name' => 'Globex Bank']);
    $this->acmeIt = userWithRole('customer_it', ['name' => 'Acme IT', 'customer_id' => $this->acme->id]);

    $category = createAssetCategory();
    $this->acmeAsset = createAsset($category, ['name' => 'Acme switch', 'customer_id' => $this->acme->id]);
    $this->globexAsset = createAsset($category, ['name' => 'Globex switch', 'customer_id' => $this->globex->id]);
    $this->acmeContract = createContract($this->acme);
    $this->globexContract = createContract($this->globex);

    $this->acmeTicket = openTicket($this->helpdesk, ['title' => 'Acme printer', 'customer_id' => $this->acme->id]);
    $this->globexTicket = openTicket($this->helpdesk, ['title' => 'Globex printer', 'customer_id' => $this->globex->id]);
});

it('keeps a customer IT account out of every other customer by URL', function () {
    // its own customer: allowed
    $this->actingAs($this->acmeIt)->get("/assets/{$this->acmeAsset->ulid}")->assertOk();
    $this->actingAs($this->acmeIt)->get("/tickets/{$this->acmeTicket->ulid}")->assertOk();
    $this->actingAs($this->acmeIt)->get("/contracts/{$this->acmeContract->id}")->assertOk();

    // another customer: refused
    foreach ([
        "/assets/{$this->globexAsset->ulid}",
        "/tickets/{$this->globexTicket->ulid}",
        "/contracts/{$this->globexContract->id}",
    ] as $url) {
        expect($this->actingAs($this->acmeIt)->get($url)->status())->toBeIn([403, 404], $url);
    }

    // nor through a list
    $this->actingAs($this->acmeIt)->get('/assets')->assertInertia(fn (Assert $page) => $page
        ->where('assets.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['Acme switch']));
    $this->actingAs($this->acmeIt)->get('/tickets')->assertInertia(fn (Assert $page) => $page
        ->where('tickets.data', fn ($rows) => collect($rows)->pluck('title')->all() === ['Acme printer']));
});

it('keeps a customer IT account out of another customer PM plan and away from staff-only pages', function () {
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $this->globexContract->id, 'title' => 'Globex PM', 'interval_months' => 6]);
    $visit = $plan->visits()->orderBy('round')->first();

    expect($this->actingAs($this->acmeIt)->get("/pm-plans/{$plan->id}")->status())->toBeIn([403, 404])
        ->and($this->actingAs($this->acmeIt)->get("/pm-visits/{$visit->ulid}")->status())->toBeIn([403, 404]);

    // must_not_see: stock, purchasing, people summary, settings, log
    foreach (['/parts', '/stock-movements', '/purchase-requests', '/summary/people', '/users', '/roles', '/activity-log', '/settings/alerts'] as $url) {
        expect($this->actingAs($this->acmeIt)->get($url)->status())->toBeIn([403, 404], $url);
    }
});

it('keeps a technician to the tickets they reported or are assigned', function () {
    $tech = userWithRole('technician', ['name' => 'Somsak Tech']);
    $other = userWithRole('technician', ['name' => 'Other Tech']);
    app(AssignTicket::class)->handle($this->acmeTicket, $tech->id, $this->helpdesk);
    app(AssignTicket::class)->handle($this->globexTicket, $other->id, $this->helpdesk);
    $mine = openTicket($tech, ['title' => 'Reported by me']);

    $this->actingAs($tech)->get("/tickets/{$this->acmeTicket->ulid}")->assertOk();
    $this->actingAs($tech)->get("/tickets/{$mine->ulid}")->assertOk();
    $this->actingAs($tech)->get("/tickets/{$this->globexTicket->ulid}")->assertForbidden();
    $this->actingAs($tech)->post("/tickets/{$this->globexTicket->ulid}/comments", ['body' => 'hi'])->assertForbidden();

    $this->actingAs($tech)->get('/tickets?status=all')->assertInertia(fn (Assert $page) => $page
        ->where('tickets.data', fn ($rows) => collect($rows)->pluck('title')->sort()->values()->all() === ['Acme printer', 'Reported by me']));

    // the helpdesk (scope all) sees every ticket
    $this->actingAs($this->helpdesk)->get("/tickets/{$this->globexTicket->ulid}")->assertOk();
});
