<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Models\CrossTenantLink;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->superadmin = createSuperadmin(['name' => 'Super']);
    $this->platform = $this->superadmin->tenant;
    $this->centralTech = userWithRole(PermissionCatalog::CENTRAL_TECHNICIAN, ['name' => 'Central Tech'], $this->platform);

    // Company A (the test's default tenant) wants to see company B's parts.
    $this->a = $this->tenant;
    $this->tech = userWithRole('technician', ['name' => 'Tech A']);
    $this->helpdesk = userWithRole('helpdesk');
    $this->adminA = userWithRole('admin_company');

    $this->b = createTenant('beta');
    $this->adminB = userWithRole('admin_company', ['name' => 'Admin B'], $this->b);
    [$this->bangna, $this->chiangmai] = asTenant($this->b, function () {
        createPart(['name' => 'SFP 10G', 'code' => 'SFP-01'], 7);
        $bangna = Branch::create(['code' => 'BN', 'name' => 'Bangna']);
        $chiangmai = Branch::create(['code' => 'CM', 'name' => 'Chiang Mai']);
        $category = createAssetCategory();
        createAsset($category, ['name' => 'Spare Switch', 'branch_id' => $bangna->id]);
        createAsset($category, ['name' => 'North Switch', 'branch_id' => $chiangmai->id]);

        return [$bangna, $chiangmai];
    });
    $this->c = createTenant('gamma');
    $this->adminC = userWithRole('admin_company', [], $this->c);

    $this->share = fn (array $data = []) => $this->actingAs($this->superadmin)
        ->put("/platform/tenants/{$this->b->ulid}/shares/{$this->a->ulid}", $data + [
            'abilities' => ['parts.view'],
            'roles' => ['technician', 'central_technician'],
            'activate' => false,
        ]);
    $this->search = fn ($user, string $query = 'kind=parts&search=sfp') => $this->actingAs($user)->get("/shared-search?{$query}");
});

it('lets technicians see another company\'s parts once that company\'s admin accepts', function () {
    ($this->share)()->assertSessionHasNoErrors();
    $share = TenantShare::first();
    expect($share->status)->toBe('pending');

    // Pending: nothing yet.
    ($this->search)($this->tech)->assertInertia(fn (Assert $page) => $page->component('Platform/SharedSearch')->where('companies', []));

    $this->actingAs($this->adminB)->get('/settings/shares')
        ->assertInertia(fn (Assert $page) => $page->where('incoming.0.company', 'Default')->where('incoming.0.status', 'pending'));
    // Another company cannot accept it.
    $this->actingAs($this->adminC)->post("/settings/shares/{$share->id}", ['decision' => 'accept'])->assertNotFound();
    // Nor can the company that asked.
    $this->actingAs($this->adminA)->post("/settings/shares/{$share->id}", ['decision' => 'accept'])->assertNotFound();

    $this->actingAs($this->adminB)->post("/settings/shares/{$share->id}", ['decision' => 'accept'])->assertSessionHasNoErrors();
    expect($share->fresh()->only(['status', 'accepted_by_name']))->toBe(['status' => 'active', 'accepted_by_name' => 'Admin B']);

    ($this->search)($this->tech)->assertInertia(fn (Assert $page) => $page
        ->where('companies.0.name', 'Beta')
        ->where('results.0.company', 'Beta')
        ->where('results.0.rows.0.code', 'SFP-01')
        ->where('results.0.rows.0.qty_on_hand', 7));

    // Only what was shared: no assets, and not for roles left out.
    ($this->search)($this->tech, 'kind=assets&search=switch')->assertInertia(fn (Assert $page) => $page->where('companies', []));
    ($this->search)($this->helpdesk)->assertInertia(fn (Assert $page) => $page->where('companies', []));

    // B's data stays in B: A still does not see B's parts in its own list.
    expect(asTenant($this->a, fn () => Part::count()))->toBe(0);
});

it('lets the superadmin put a share in force at once, and either company revoke it', function () {
    ($this->share)(['activate' => true, 'abilities' => ['parts.view', 'assets.view']])->assertSessionHasNoErrors();
    expect(TenantShare::first()->status)->toBe('active');

    ($this->search)($this->tech, 'kind=assets&search=switch')
        ->assertInertia(fn (Assert $page) => $page->where('results.0.rows.0.name', 'Spare Switch'));

    $this->actingAs($this->adminA)->post('/settings/shares/'.TenantShare::first()->id, ['decision' => 'revoke'])->assertSessionHasNoErrors();
    ($this->search)($this->tech)->assertInertia(fn (Assert $page) => $page->where('companies', []));
});

it('shows each company only what was shared with it: named people, and assets of chosen branches', function () {
    $named = userWithRole('user', ['name' => 'Named User']);
    grantTo('user', ['assets.view']);
    ($this->share)(['activate' => true, 'abilities' => ['assets.view'], 'roles' => [], 'user_ids' => [$named->id], 'branch_ids' => [$this->bangna->id]])
        ->assertSessionHasNoErrors();

    // The named person sees only Bangna's assets; technicians (no longer listed) see nothing.
    ($this->search)($named, 'kind=assets&search=switch')->assertInertia(fn (Assert $page) => $page
        ->where('results.0.rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Spare Switch']));
    ($this->search)($this->tech, 'kind=assets&search=switch')->assertInertia(fn (Assert $page) => $page->where('companies', []));

    // Company C was not given anything.
    $techC = userWithRole('technician', [], $this->c);
    ($this->search)($techC, 'kind=assets&search=switch')->assertInertia(fn (Assert $page) => $page->where('companies', []));

    // Who must be named somehow.
    ($this->share)(['roles' => [], 'user_ids' => []])->assertSessionHasErrors(['roles', 'user_ids']);
});

it('stops an expired share', function () {
    ($this->share)(['activate' => true]);
    TenantShare::query()->update(['expires_on' => now()->subDay()->toDateString()]);

    ($this->search)($this->tech)->assertInertia(fn (Assert $page) => $page->where('companies', []));
});

it('lets central technicians use the share of the company they work in', function () {
    ($this->share)(['activate' => true]);

    $this->actingAs($this->centralTech)->post("/platform/impersonation/{$this->a->ulid}");
    ($this->search)($this->centralTech)->assertInertia(fn (Assert $page) => $page->where('results.0.rows.0.code', 'SFP-01'));
});

it('keeps sharing settings to the superadmin and customer accounts out', function () {
    $this->actingAs($this->adminA)->put("/platform/tenants/{$this->b->ulid}/shares/{$this->a->ulid}", [
        'abilities' => ['parts.view'], 'roles' => ['technician'], 'activate' => true,
    ])->assertForbidden();

    ($this->share)(['activate' => true]);
    $client = userWithRole('customer_it', ['customer_id' => createCustomer()->id]);
    ($this->search)($client)->assertForbidden();
});

it('asks another company for parts for a ticket, which that company approves and hands out', function () {
    ($this->share)(['activate' => true, 'abilities' => ['parts.view', 'parts.request']]);
    $ticket = openTicket($this->tech);
    $partB = asTenant($this->b, fn () => Part::where('code', 'SFP-01')->first());

    $this->actingAs($this->tech)->post('/shared-search/requests', [
        'company' => $this->b->id,
        'ticket_id' => $ticket->id,
        'purpose' => 'Uplink',
        'items' => [['item_type' => 'part', 'id' => $partB->id, 'qty' => 2]],
    ])->assertSessionHasNoErrors();

    // Made in B, waiting for B's approver; nothing of it in A.
    $request = asTenant($this->b, fn () => CheckoutRequest::with('items')->first());
    expect($request->only(['status', 'requester_id', 'requester_name', 'borrower_name', 'ticket_id']))
        ->toBe(['status' => 'pending', 'requester_id' => null, 'requester_name' => 'Tech A (Default)', 'borrower_name' => 'Tech A (Default)', 'ticket_id' => null])
        ->and($request->purpose)->toContain($ticket->ticket_no)
        ->and(CheckoutRequest::count())->toBe(0);

    $this->actingAs($this->adminB)->get("/checkout-requests/{$request->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('askedBy', ['company' => 'Default', 'ticket_no' => $ticket->ticket_no, 'by' => 'Tech A']));
    $this->actingAs($this->adminB)->post("/checkout-requests/{$request->ulid}/approve")->assertSessionHasNoErrors();
    $this->actingAs($this->adminB)->post("/checkout-items/{$request->items->first()->id}/fulfill", ['qty' => 2])->assertSessionHasNoErrors();

    asTenant($this->b, function () {
        expect(Part::where('code', 'SFP-01')->value('qty_on_hand'))->toBe(5)
            ->and(StockMovement::latest('id')->first()->only(['type', 'quantity', 'ticket_id']))->toBe(['type' => 'issue', 'quantity' => -2, 'ticket_id' => null]);
    });

    // A follows it on its ticket.
    $this->actingAs($this->tech)->get("/tickets/{$ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('sharedRequests.0.company', 'Beta')
        ->where('sharedRequests.0.request_no', $request->request_no)
        ->where('sharedRequests.0.status', 'fulfilled')
        ->where('sharedRequests.0.items.0.qty_fulfilled', 2));
    expect(CrossTenantLink::first()->only(['source_id', 'target_id']))->toBe(['source_id' => $ticket->id, 'target_id' => $request->id]);
});

it('asks only for what the share allows', function () {
    ($this->share)(['activate' => true, 'abilities' => ['parts.view', 'assets.view', 'assets.request'], 'branch_ids' => [$this->bangna->id]]);
    [$partB, $northSwitch] = asTenant($this->b, fn () => [Part::first(), Asset::where('name', 'North Switch')->first()]);

    // Parts were not shared for asking.
    $this->actingAs($this->tech)->post('/shared-search/requests', ['company' => $this->b->id, 'items' => [['item_type' => 'part', 'id' => $partB->id, 'qty' => 1]]])
        ->assertForbidden();
    // An asset of a branch that is not shared.
    $this->actingAs($this->tech)->post('/shared-search/requests', ['company' => $this->b->id, 'items' => [['item_type' => 'asset', 'id' => $northSwitch->id, 'qty' => 1]]])
        ->assertSessionHasErrors('items');
    expect(asTenant($this->b, fn () => CheckoutRequest::count()))->toBe(0);
});

it('shows the superadmin, for one company, every other company to share with', function () {
    ($this->share)(['activate' => true]);

    $this->actingAs($this->superadmin)->get("/platform/tenants/{$this->b->ulid}/shares")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Tenants/Shares')
            ->where('tenant.name', 'Beta')
            ->where('companies', fn ($companies) => collect($companies)->pluck('name')->sort()->values()->all() === ['Default', 'Gamma'])
            ->where('companies', fn ($companies) => collect($companies)->firstWhere('name', 'Default')['share']['status'] === 'active'
                && collect($companies)->firstWhere('name', 'Gamma')['share'] === null
                && collect(collect($companies)->firstWhere('name', 'Default')['people'])->pluck('name')->contains('Tech A'))
            ->where('branches', fn ($branches) => collect($branches)->pluck('name')->sort()->values()->all() === ['Bangna', 'Chiang Mai']));
});

it('forwards a ticket to another company and follows it there', function () {
    ($this->share)(['activate' => true, 'abilities' => ['tickets.forward']]);
    $ticket = openTicket($this->tech, ['title' => 'Core switch down', 'contact_name' => 'Somsri', 'priority' => 'high', 'device_name' => 'Core SW']);

    $this->actingAs($this->tech)->get("/tickets/{$ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('forwards.companies.0.name', 'Beta')->where('forwards.tickets', []));

    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/forward", ['company' => $this->b->id, 'note' => 'Please check on site'])
        ->assertSessionHasNoErrors();

    // B has its own ticket, nobody of B linked as the opener.
    $theirs = asTenant($this->b, fn () => Ticket::first());
    expect($theirs->only(['title', 'priority', 'contact_name', 'device_name', 'source', 'reported_by', 'status']))
        ->toBe(['title' => 'Core switch down', 'priority' => 'high', 'contact_name' => 'Somsri', 'device_name' => 'Core SW', 'source' => 'partner', 'reported_by' => null, 'status' => 'new'])
        ->and($theirs->description)->toStartWith('Please check on site')
        ->and($theirs->ticket_no)->not->toBeNull();

    $this->actingAs($this->adminB)->get("/tickets/{$theirs->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('forwards.from', ['company' => 'Default', 'ticket_no' => $ticket->ticket_no, 'by' => 'Tech A']));

    // B works on it; A sees each move on its own ticket.
    $techB = userWithRole('technician', ['name' => 'Tech B'], $this->b);
    $this->actingAs($this->adminB)->post("/tickets/{$theirs->ulid}/assign", ['assignee_id' => $techB->id])->assertSessionHasNoErrors();
    asTenant($this->b, fn () => checkWarranty($theirs->fresh(), $techB));
    $this->actingAs($techB)->post("/tickets/{$theirs->ulid}/move", ['action' => 'start'])->assertSessionHasNoErrors();

    $this->actingAs($this->tech)->get("/tickets/{$ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('forwards.tickets.0.company', 'Beta')
        ->where('forwards.tickets.0.ticket_no', $theirs->ticket_no)
        ->where('forwards.tickets.0.status', 'in_progress')
        ->where('forwards.tickets.0.assignee', 'Tech B'));
    expect($ticket->events()->where('type', 'comment')->where('is_internal', true)->pluck('body')->all())
        ->toHaveCount(2)
        ->sequence(fn ($body) => $body->toContain($theirs->ticket_no), fn ($body) => $body->toContain('Tech B'));
});

it('forwards only to companies that take tickets from us', function () {
    ($this->share)(['activate' => true, 'abilities' => ['parts.view']]);
    $ticket = openTicket($this->tech);

    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/forward", ['company' => $this->b->id])->assertForbidden();
    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/forward", ['company' => $this->c->id])->assertForbidden();
    expect(asTenant($this->b, fn () => Ticket::count()))->toBe(0);
});
