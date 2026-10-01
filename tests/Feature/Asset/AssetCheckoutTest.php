<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->staff = userWithRole('user', ['name' => 'Somchai Office']);
    $this->asset = createAsset(createAssetCategory(), [
        'name' => 'Notebook Lenovo', 'brand' => 'Lenovo', 'model' => 'E14', 'serial_number' => 'SN-1', 'status' => Asset::STATUS_SPARE,
    ]);
    $this->loan = fn (array $data = []) => $this->actingAs($this->tech)->post("/assets/{$this->asset->ulid}/checkouts", $data + [
        'type' => 'loan', 'borrower_user_id' => $this->staff->id, 'borrower_department' => 'Accounting', 'due_on' => '2026-10-15', 'purpose' => 'Work from home',
    ]);
});

it('asks to lend an asset to a staff member, numbered per Buddhist year, waiting for approval', function () {
    ($this->loan)()->assertSessionHasNoErrors();

    $checkout = AssetCheckout::sole();
    expect($checkout->only(['checkout_no', 'type', 'status', 'borrower_name', 'borrower_user_id', 'requested_by_name']))->toBe([
        'checkout_no' => 'AC-2569-00001', 'type' => 'loan', 'status' => 'pending',
        'borrower_name' => 'Somchai Office', 'borrower_user_id' => $this->staff->id, 'requested_by_name' => 'Tech One',
    ])
        ->and($checkout->due_on->toDateString())->toBe('2026-10-15')
        // nothing is handed over before approval
        ->and($this->asset->fresh()->status)->toBe(Asset::STATUS_SPARE);

    // one request at a time
    ($this->loan)()->assertSessionHasErrors('type');
});

it('issues to someone from outside without a due date', function () {
    $this->actingAs($this->tech)->post("/assets/{$this->asset->ulid}/checkouts", [
        'type' => 'issue', 'borrower_name' => 'Customer staff', 'borrower_department' => 'Acme', 'due_on' => '2026-12-01',
    ])->assertSessionHasNoErrors();

    expect(AssetCheckout::sole()->only(['borrower_name', 'borrower_user_id']))->toBe(['borrower_name' => 'Customer staff', 'borrower_user_id' => null])
        ->and(AssetCheckout::sole()->due_on)->toBeNull();
});

it('validates the request', function () {
    ($this->loan)(['due_on' => null])->assertSessionHasErrors('due_on');
    ($this->loan)(['due_on' => '2026-09-01'])->assertSessionHasErrors('due_on');
    ($this->loan)(['borrower_user_id' => null, 'borrower_name' => ''])->assertSessionHasErrors('borrower_name');

    // a user of another company, or a customer account, is not a borrower to choose
    $outsider = userWithRole('admin_company', [], createTenant('other'));
    $client = userWithRole('customer', ['customer_id' => createCustomer()->id]);
    ($this->loan)(['borrower_user_id' => $outsider->id])->assertSessionHasErrors('borrower_user_id');
    ($this->loan)(['borrower_user_id' => $client->id])->assertSessionHasErrors('borrower_user_id');

    // an asset under repair or retired cannot go out
    $this->asset->update(['status' => Asset::STATUS_IN_REPAIR]);
    ($this->loan)()->assertSessionHasErrors('type');

    expect(AssetCheckout::count())->toBe(0);
});

it('hands the asset over on approval, and takes it back', function () {
    ($this->loan)();
    $checkout = AssetCheckout::sole();

    // a technician may ask but not approve
    $this->actingAs($this->tech)->post("/asset-checkouts/{$checkout->ulid}/approve")->assertForbidden();

    $this->actingAs($this->admin)->post("/asset-checkouts/{$checkout->ulid}/approve")->assertSessionHasNoErrors();
    expect($checkout->fresh()->only(['status', 'decided_by_name']))->toBe(['status' => 'approved', 'decided_by_name' => 'Admin Boss'])
        ->and($this->asset->fresh()->status)->toBe(Asset::STATUS_IN_USE);

    // the device page and the same-model table show who has it
    $this->actingAs($this->tech)->get("/assets/{$this->asset->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('checkouts.current.checkout_no', 'AC-2569-00001')
        ->where('checkouts.available', false)
        ->where('sameModel.0.holder', 'Somchai Office'));

    $this->actingAs($this->tech)->post("/asset-checkouts/{$checkout->ulid}/return", ['note' => 'OK, no scratches'])->assertSessionHasNoErrors();
    expect($checkout->fresh()->only(['status', 'returned_by_name', 'return_note']))->toBe(['status' => 'returned', 'returned_by_name' => 'Tech One', 'return_note' => 'OK, no scratches'])
        ->and($this->asset->fresh()->status)->toBe(Asset::STATUS_SPARE);

    // free again
    ($this->loan)()->assertSessionHasNoErrors();
});

it('rejects with a reason, and lets the requester cancel', function () {
    ($this->loan)();
    $checkout = AssetCheckout::sole();

    $this->actingAs($this->admin)->post("/asset-checkouts/{$checkout->ulid}/reject")->assertSessionHasErrors('note');
    $this->actingAs($this->admin)->post("/asset-checkouts/{$checkout->ulid}/reject", ['note' => 'Needed for the project'])->assertSessionHasNoErrors();
    expect($checkout->fresh()->only(['status', 'decision_note']))->toBe(['status' => 'rejected', 'decision_note' => 'Needed for the project'])
        ->and($this->asset->fresh()->status)->toBe(Asset::STATUS_SPARE);

    ($this->loan)();
    $second = AssetCheckout::where('status', 'pending')->sole();
    $this->actingAs(userWithRole('technician'))->post("/asset-checkouts/{$second->ulid}/cancel")->assertForbidden();
    $this->actingAs($this->tech)->post("/asset-checkouts/{$second->ulid}/cancel")->assertSessionHasNoErrors();
    expect($second->fresh()->status)->toBe('cancelled');
});

it('prints the hand-over form once approved, as a page or a PDF', function () {
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf'])]);
    ($this->loan)();
    $checkout = AssetCheckout::sole();

    // not before approval
    $this->actingAs($this->tech)->get("/asset-checkouts/{$checkout->ulid}/print")->assertNotFound();

    $this->actingAs($this->admin)->post("/asset-checkouts/{$checkout->ulid}/approve");

    $this->actingAs($this->tech)->get("/asset-checkouts/{$checkout->ulid}/print")->assertOk()
        ->assertSee('ใบยืมทรัพย์สิน')
        ->assertSee('AC-2569-00001')
        ->assertSee('Somchai Office')
        ->assertSee('SN-1')
        ->assertSee('15 ตุลาคม 2569')
        ->assertSee('window.print', false);

    $this->actingAs($this->tech)->get("/asset-checkouts/{$checkout->ulid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

    // someone who neither asks nor approves cannot print it
    $this->actingAs($this->staff)->get("/asset-checkouts/{$checkout->ulid}/print")->assertForbidden();
});

it('lists the forms with search, filters and sort, within what the user may see', function () {
    $north = Branch::create(['code' => 'N', 'name' => 'North']);
    $northTech = userWithRole('technician', ['branch_id' => $north->id]);
    $south = createAsset(createAssetCategory(), ['name' => 'South printer', 'branch_id' => Branch::create(['code' => 'S', 'name' => 'South'])->id]);

    ($this->loan)();
    $this->actingAs($this->admin)->post('/asset-checkouts/'.AssetCheckout::sole()->ulid.'/approve');
    $this->actingAs($this->admin)->post("/assets/{$south->ulid}/checkouts", ['type' => 'issue', 'borrower_name' => 'Somsri']);

    $this->actingAs($this->admin)->get('/asset-checkouts')->assertInertia(fn (Assert $page) => $page
        ->component('Asset/Checkouts/Index')
        ->where('checkouts.total', 2));
    $this->actingAs($this->admin)->get('/asset-checkouts?search=Somsri')->assertInertia(fn (Assert $page) => $page->where('checkouts.total', 1));
    $this->actingAs($this->admin)->get('/asset-checkouts?status=pending')->assertInertia(fn (Assert $page) => $page->where('checkouts.data.0.borrower_name', 'Somsri'));
    $this->actingAs($this->admin)->get('/asset-checkouts?type=loan')->assertInertia(fn (Assert $page) => $page->where('checkouts.total', 1));

    // past the due date
    $this->travelTo('2026-10-20 10:00');
    $this->actingAs($this->admin)->get('/asset-checkouts?status=overdue')->assertInertia(fn (Assert $page) => $page
        ->where('checkouts.total', 1)
        ->where('checkouts.data.0.overdue', true));

    // a technician of the north branch does not see the south asset's form
    $this->actingAs($northTech)->get('/asset-checkouts')->assertInertia(fn (Assert $page) => $page->where('checkouts.total', 1));
    // and someone without the permissions does not get the page
    $this->actingAs($this->staff)->get('/asset-checkouts')->assertForbidden();
});

it('starts a request from a search of the spare devices, grouped by model', function () {
    $category = createAssetCategory(['name' => 'Notebook']);
    createAsset($category, ['name' => 'Notebook Dell', 'brand' => 'Dell', 'model' => '5440', 'status' => Asset::STATUS_SPARE]);
    createAsset($category, ['name' => 'Notebook Dell', 'brand' => 'Dell', 'model' => '5440', 'status' => Asset::STATUS_SPARE]);
    createAsset($category, ['name' => 'Notebook Dell', 'brand' => 'Dell', 'model' => '5440', 'status' => Asset::STATUS_IN_USE]); // not spare
    $out = createAsset($category, ['name' => 'Notebook Dell', 'brand' => 'Dell', 'model' => '5440', 'status' => Asset::STATUS_SPARE]);
    $this->actingAs($this->tech)->post("/assets/{$out->ulid}/checkouts", ['type' => 'issue', 'borrower_name' => 'X']); // asked for already

    $this->actingAs($this->tech)->get('/asset-checkouts/create?search=dell')->assertInertia(fn (Assert $page) => $page
        ->component('Asset/Checkouts/Create')
        ->has('groups', 1)
        ->where('groups.0.brand', 'Dell')
        ->has('groups.0.units', 2)
        ->where('canPurchase', true));

    // nothing spare: the page offers a purchase request instead
    $this->actingAs($this->tech)->get('/asset-checkouts/create?search=projector')->assertInertia(fn (Assert $page) => $page->where('groups', []));

    // a request sent from the search goes on to the list
    $unit = Asset::where('brand', 'Dell')->where('status', Asset::STATUS_SPARE)->whereKeyNot($out->id)->first();
    $this->actingAs($this->tech)->post("/assets/{$unit->ulid}/checkouts", ['type' => 'issue', 'borrower_name' => 'Y', 'from_search' => true])
        ->assertRedirect(route('asset.checkouts.index'));

    $this->actingAs($this->staff)->get('/asset-checkouts/create')->assertForbidden();
});

it('keeps each company to its own forms', function () {
    ($this->loan)();
    $checkout = AssetCheckout::sole();

    $otherAdmin = userWithRole('admin_company', [], createTenant('other'));
    $this->actingAs($otherAdmin)->post("/asset-checkouts/{$checkout->ulid}/approve")->assertNotFound();
    $this->actingAs($otherAdmin)->get("/asset-checkouts/{$checkout->ulid}/print")->assertNotFound();
    $this->actingAs($otherAdmin)->get('/asset-checkouts')->assertInertia(fn (Assert $page) => $page->where('checkouts.total', 0));
    expect($checkout->fresh()->status)->toBe('pending');
});
