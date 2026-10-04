<?php

use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Models\Part;
use App\Modules\Platform\Models\TenantShare;
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
