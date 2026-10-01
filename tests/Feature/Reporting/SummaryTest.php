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
    $this->contract = createContract(createCustomer(['name' => 'Acme Hospital']), ['contract_no' => 'MA-2026-01', 'title' => 'Network MA']);
    $this->other = createContract(createCustomer(), ['contract_no' => 'MA-2026-02', 'title' => 'PC MA']);

    $category = createAssetCategory(['name' => 'Switch']);
    $this->switch = createAsset($category, ['name' => 'Switch 24 port', 'status' => Asset::STATUS_SPARE]);
    $this->cables = createAsset($category, ['name' => 'สาย LAN', 'quantity' => 24, 'unit' => 'เส้น', 'status' => Asset::STATUS_SPARE]);

    $this->checkout = fn (Asset $asset, array $data) => $this->actingAs($this->tech)->post("/assets/{$asset->ulid}/checkouts", $data + [
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
        $this->actingAs($this->tech)->get($url)->assertForbidden();
        $this->actingAs($this->staff)->get($url)->assertForbidden();
    }

    // without the contract module there are no projects; without reporting, no summaries
    Feature::for($this->tenant)->deactivate(Modules::feature('contract'));
    $this->actingAs($this->admin)->get('/summary/projects')->assertNotFound();
    $this->actingAs($this->admin)->get('/summary/people')->assertOk();
    Feature::for($this->tenant)->deactivate(Modules::feature('reporting'));
    $this->actingAs($this->admin)->get('/summary/people')->assertNotFound();
});
