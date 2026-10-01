<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Modules;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->tech = userWithRole('technician', ['name' => 'Somsak Tech']);
    $this->staff = userWithRole('user', ['name' => 'Somchai Office']);
    // Office staff here may ask to buy (their own requests).
    grantTo('user', ['purchase-requests.view', 'purchase-requests.create'], 'own');
    $this->contract = createContract(createCustomer(['name' => 'Acme Hospital']), ['contract_no' => 'MA-2026-01', 'title' => 'Network MA']);
    $this->other = createContract(createCustomer(), ['contract_no' => 'MA-2026-02', 'title' => 'PC MA']);

    $category = createAssetCategory(['name' => 'Switch']);
    $this->switch = createAsset($category, ['name' => 'Switch 24 port', 'status' => Asset::STATUS_SPARE]);
    $this->cables = createAsset($category, ['name' => 'สาย LAN', 'quantity' => 24, 'unit' => 'เส้น', 'status' => Asset::STATUS_SPARE]);

    // The office makes the forms (asset-checkouts.create: for anyone, or someone from outside).
    $this->checkout = fn (Asset $asset, array $data) => $this->actingAs($this->admin)->post("/assets/{$asset->ulid}/checkouts", $data + [
        'type' => 'issue', 'quantity' => 1,
    ])->assertSessionHasNoErrors();
    $this->buy = fn ($user, array $data = []) => $this->actingAs($user)->post('/purchase-requests', $data + [
        'item_name' => 'SFP module', 'quantity' => 2, 'unit' => 'ชิ้น', 'unit_price' => '1500.00',
        'links' => ['https://shop.example.com/sfp'], 'reason' => 'Uplink', 'needed_by' => '2026-11-01',
    ])->assertSessionHasNoErrors();
});

it('keeps the project on issue/loan forms and purchase requests', function () {
    $this->actingAs($this->tech)->get("/assets/{$this->switch->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('checkouts.contracts', fn ($contracts) => collect($contracts)->pluck('label')->contains('MA-2026-01 · Network MA · Acme Hospital')));
    $this->actingAs($this->staff)->get('/purchase-requests/create')->assertInertia(fn (Assert $page) => $page->has('contracts', 2));

    ($this->checkout)($this->switch, ['borrower_user_id' => $this->tech->id, 'contract_id' => $this->contract->id]);
    ($this->buy)($this->staff, ['contract_id' => $this->contract->id]);

    expect(AssetCheckout::sole()->contract_id)->toBe($this->contract->id)
        ->and(PurchaseRequest::sole()->contract_id)->toBe($this->contract->id);

    $pr = PurchaseRequest::sole();
    $this->actingAs($this->staff)->get("/purchase-requests/{$pr->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('contract.contract_no', 'MA-2026-01'));

    // no project is fine; a contract of another tenant is not
    ($this->buy)($this->staff, ['contract_id' => null]);
    $foreign = asTenant(createTenant('other'), fn () => createContract(createCustomer()));
    $this->actingAs($this->staff)->post('/purchase-requests', [
        'item_name' => 'X', 'quantity' => 1, 'unit' => 'ชิ้น', 'links' => ['https://shop.example.com/x'], 'reason' => 'x',
        'needed_by' => '2026-11-01', 'contract_id' => $foreign->id,
    ])->assertSessionHasErrors('contract_id');
});

it('sums up what each person borrowed, was issued and asked to buy', function () {
    ($this->checkout)($this->switch, ['type' => 'loan', 'due_on' => '2026-10-10', 'borrower_user_id' => $this->tech->id, 'contract_id' => $this->contract->id]);
    ($this->checkout)($this->cables, ['quantity' => 5, 'borrower_user_id' => $this->tech->id]);
    ($this->checkout)($this->cables, ['quantity' => 2, 'borrower_name' => 'Contractor Lek']);
    ($this->buy)($this->tech, ['contract_id' => $this->contract->id]);
    ($this->buy)($this->staff);

    // a cancelled form never went out: not counted
    ($this->checkout)($this->cables, ['quantity' => 1, 'borrower_user_id' => $this->staff->id]);
    AssetCheckout::latest('id')->first()->update(['status' => AssetCheckout::STATUS_CANCELLED]);

    // the loan comes back: still counted, no longer open
    $loan = AssetCheckout::where('type', 'loan')->sole();
    $this->actingAs($this->admin)->post("/asset-checkouts/{$loan->ulid}/approve")->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/asset-checkouts/{$loan->ulid}/return")->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/summary/people?sort=name&direction=asc')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reporting/People/Index')
        ->where('people.total', 3)
        ->where('people.data.0.name', 'Contractor Lek')
        ->where('people.data.0.user_id', null)
        ->where('people.data.0.outside_name', 'Contractor Lek')
        ->where('people.data.0.issues', 1)
        ->where('people.data.1.name', 'Somchai Office')
        ->where('people.data.1.purchases', 1)
        ->where('people.data.1.issues', 0)
        ->where('people.data.1.purchase_amount', '3000.00')
        ->where('people.data.2.name', 'Somsak Tech')
        ->where('people.data.2.user_id', $this->tech->id)
        ->where('people.data.2.issues', 1)
        ->where('people.data.2.loans', 1)
        ->where('people.data.2.loans_open', 0)
        ->where('people.data.2.purchases', 1)
        ->where('people.data.2.open_count', 2)
        ->where('people.data.2.total', 3));

    // search, kind and open-only are done by the server
    $this->actingAs($this->admin)->get('/summary/people?search=somsak')->assertInertia(fn (Assert $page) => $page
        ->where('people.total', 1)->where('people.data.0.name', 'Somsak Tech'));
    $this->actingAs($this->admin)->get('/summary/people?kind=purchase')->assertInertia(fn (Assert $page) => $page->where('people.total', 2));
    $this->actingAs($this->admin)->get('/summary/people?kind=loan&show=open')->assertInertia(fn (Assert $page) => $page->where('people.total', 0));
    $this->actingAs($this->admin)->get('/summary/people?sort=total&direction=desc')->assertInertia(fn (Assert $page) => $page
        ->where('people.data.0.name', 'Somsak Tech'));
});

it('shows one person with their forms and purchases', function () {
    ($this->checkout)($this->switch, ['borrower_user_id' => $this->tech->id, 'contract_id' => $this->contract->id]);
    ($this->checkout)($this->cables, ['quantity' => 3, 'borrower_name' => 'Contractor Lek']);
    ($this->buy)($this->tech, ['contract_id' => $this->contract->id]);
    ($this->buy)($this->staff);

    $this->actingAs($this->admin)->get("/summary/people/view?user={$this->tech->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reporting/People/Show')
        ->where('person.name', 'Somsak Tech')
        ->where('totals.issues', 1)
        ->where('totals.purchases', 1)
        ->has('checkouts.data', 1)
        ->where('checkouts.data.0.contract.contract_no', 'MA-2026-01')
        ->has('purchases.data', 1)
        ->where('purchases.data.0.item_name', 'SFP module'));

    // someone from outside: by name, loans and issues only
    $this->actingAs($this->admin)->get('/summary/people/view?name='.urlencode('Contractor Lek'))->assertInertia(fn (Assert $page) => $page
        ->where('person.outside_name', 'Contractor Lek')
        ->where('totals.issues', 1)
        ->where('checkouts.data.0.quantity', 3)
        ->where('purchases', null));

    $this->actingAs($this->admin)->get('/summary/people/view')->assertNotFound();
    $this->actingAs($this->admin)->get('/summary/people/view?user=999999')->assertNotFound();
});

it('sums up what went out and was bought for each project', function () {
    ($this->checkout)($this->switch, ['borrower_user_id' => $this->tech->id, 'contract_id' => $this->contract->id]);
    ($this->checkout)($this->cables, ['type' => 'loan', 'due_on' => '2026-10-10', 'quantity' => 4, 'borrower_name' => 'Contractor Lek', 'contract_id' => $this->contract->id]);
    ($this->checkout)($this->cables, ['quantity' => 1, 'borrower_user_id' => $this->tech->id]);
    ($this->buy)($this->staff, ['contract_id' => $this->contract->id]);

    // only projects with something, unless all contracts are asked for
    $this->actingAs($this->admin)->get('/summary/projects')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reporting/Projects/Index')
        ->where('projects.total', 1)
        ->where('projects.data.0.contract_no', 'MA-2026-01')
        ->where('projects.data.0.customer', 'Acme Hospital')
        ->where('projects.data.0.phase', 'active')
        ->where('projects.data.0.issues', 1)
        ->where('projects.data.0.loans', 1)
        ->where('projects.data.0.purchases', 1)
        ->where('projects.data.0.purchase_amount', '3000.00'));
    $this->actingAs($this->admin)->get('/summary/projects?items=all&sort=contract_no&direction=desc')->assertInertia(fn (Assert $page) => $page
        ->where('projects.total', 2)
        ->where('projects.data.0.contract_no', 'MA-2026-02')
        ->where('projects.data.0.total', 0));
    $this->actingAs($this->admin)->get('/summary/projects?search=acme')->assertInertia(fn (Assert $page) => $page->where('projects.total', 1));

    $this->actingAs($this->admin)->get("/summary/projects/{$this->contract->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reporting/Projects/Show')
        ->where('project.contract_no', 'MA-2026-01')
        ->where('totals.total', 3)
        ->has('checkouts.data', 2)
        ->has('purchases.data', 1));
    $this->actingAs($this->admin)->get("/summary/projects/{$this->contract->id}?search=lek")->assertInertia(fn (Assert $page) => $page
        ->has('checkouts.data', 1)->where('checkouts.data.0.borrower_name', 'Contractor Lek'));

    $this->actingAs($this->admin)->get('/summary/projects/999999')->assertNotFound();
});

it('is for office staff, follows the modules and never shows another tenant', function () {
    $this->get('/summary/people')->assertRedirect('/login');
    ($this->checkout)($this->switch, ['borrower_user_id' => $this->tech->id, 'contract_id' => $this->contract->id]);

    $other = createTenant('other');
    $foreign = asTenant($other, function () {
        $contract = createContract(createCustomer(), ['contract_no' => 'MA-OTHER']);
        $asset = createAsset(createAssetCategory(), ['status' => Asset::STATUS_SPARE]);
        $user = userWithRole('technician', ['name' => 'Other Tech']);
        AssetCheckout::create([
            'asset_id' => $asset->id, 'contract_id' => $contract->id, 'checkout_no' => 'CO-X', 'type' => 'issue',
            'borrower_user_id' => $user->id, 'borrower_name' => 'Other Tech', 'requested_by' => $user->id,
        ]);

        return compact('contract', 'user');
    });

    $this->actingAs($this->admin)->get('/summary/people')->assertInertia(fn (Assert $page) => $page
        ->where('people.total', 1)->where('people.data.0.name', 'Somsak Tech'));
    $this->actingAs($this->admin)->get('/summary/projects?items=all')->assertInertia(fn (Assert $page) => $page->where('projects.total', 2));
    $this->actingAs($this->admin)->get("/summary/projects/{$foreign['contract']->id}")->assertNotFound();
    $this->actingAs($this->admin)->get("/summary/people/view?user={$foreign['user']->id}")->assertNotFound();

    foreach (['/summary/people', '/summary/projects', "/summary/projects/{$this->contract->id}", "/summary/people/view?user={$this->tech->id}"] as $url) {
        $this->actingAs($this->staff)->get($url)->assertForbidden();
    }
    // a technician has no project summary (their own person summary: below)
    $this->actingAs($this->tech)->get('/summary/projects')->assertForbidden();
    $this->actingAs($this->tech)->get("/summary/projects/{$this->contract->id}")->assertForbidden();

    // without the contract module there are no projects; without reporting, no summaries
    Feature::for($this->tenant)->deactivate(Modules::feature('contract'));
    $this->actingAs($this->admin)->get('/summary/projects')->assertNotFound();
    $this->actingAs($this->admin)->get('/summary/people')->assertOk();
    Feature::for($this->tenant)->deactivate(Modules::feature('reporting'));
    $this->actingAs($this->admin)->get('/summary/people')->assertNotFound();
});

it('shows a technician only their own person summary (scope own)', function () {
    ($this->checkout)($this->switch, ['borrower_user_id' => $this->tech->id, 'contract_id' => $this->contract->id]);
    ($this->checkout)($this->cables, ['quantity' => 2, 'borrower_name' => 'Contractor Lek']);
    ($this->buy)($this->staff);

    $this->actingAs($this->tech)->get('/summary/people')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('people.total', 1)
        ->where('people.data.0.user_id', $this->tech->id));
    $this->actingAs($this->tech)->get("/summary/people/view?user={$this->tech->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('totals.issues', 1));
    $this->actingAs($this->tech)->get("/summary/people/view?user={$this->staff->id}")->assertForbidden();
    $this->actingAs($this->tech)->get('/summary/people/view?name='.urlencode('Contractor Lek'))->assertForbidden();

    // with scope all, everyone in the forms they may see (not the office's purchase: purchase-requests.view is own)
    setRoleScope('technician', 'all', ['summary-people.view']);
    $this->actingAs($this->tech)->get('/summary/people')->assertInertia(fn (Assert $page) => $page->where('people.total', 2));
});

it('shows a customer account only the projects of its customer, never purchases or amounts', function () {
    ($this->checkout)($this->switch, ['borrower_user_id' => $this->tech->id, 'contract_id' => $this->contract->id]);
    ($this->checkout)($this->cables, ['quantity' => 1, 'borrower_name' => 'Contractor Lek', 'contract_id' => $this->other->id]);
    ($this->buy)($this->staff, ['contract_id' => $this->contract->id]);

    // the switch is the customer's own (a customer account sees only its customer's assets)
    $this->switch->update(['customer_id' => $this->contract->customer_id]);
    $client = userWithRole('customer_it', ['customer_id' => $this->contract->customer_id]);

    $this->actingAs($client)->get('/summary/projects?items=all')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('projects.total', 1)
        ->where('projects.data.0.contract_no', 'MA-2026-01')
        ->where('projects.data.0', fn ($row) => ! collect($row)->has(['purchases']) && ! collect($row)->has('purchase_amount')
            && ! collect($row)->has('purchases_open'))
        ->where('showsPurchases', false)
        ->where('customers', fn ($customers) => collect($customers)->pluck('id')->all() === [$this->contract->customer_id]));

    $this->actingAs($client)->get("/summary/projects/{$this->contract->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('purchases', null)
        ->where('totals.total', 1)
        ->where('totals', fn ($totals) => ! collect($totals)->has('purchase_amount') && ! collect($totals)->has('purchases')));
    $this->actingAs($client)->get("/summary/projects/{$this->other->id}")->assertForbidden();

    // and no person summary at all
    $this->actingAs($client)->get('/summary/people')->assertForbidden();
});
