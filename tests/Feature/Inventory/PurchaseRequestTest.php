<?php

use App\Modules\Inventory\Actions\PurchaseSummaryRows;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    // Technicians ask (scope own); here they may also change their own pending requests.
    $this->staff = userWithRole('technician', ['name' => 'Somchai Office']);
    grantTo('technician', ['purchase-requests.update'], 'own');
    $this->payload = [
        'item_name' => 'Notebook for accounting', 'description' => 'RAM 16GB', 'quantity' => 2, 'unit' => 'เครื่อง',
        'unit_price' => '25900.50', 'links' => ['https://shop.example.com/lenovo-e14', ''], 'reason' => 'New staff', 'needed_by' => '2026-11-01',
    ];
});

it('lets staff ask to buy something, with product links and a quotation', function () {
    $this->actingAs($this->staff)->get('/purchase-requests/create?item=notebook')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/PurchaseRequests/Form')->where('item', 'notebook'));

    $this->actingAs($this->staff)->post('/purchase-requests', [
        ...$this->payload,
        'attachments' => [UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%%EOF")],
    ])->assertSessionHasNoErrors();

    $pr = PurchaseRequest::sole();
    expect($pr->only(['pr_no', 'status', 'quantity', 'unit_price', 'links', 'requested_by_name']))->toBe([
        'pr_no' => 'PR-2569-00001', 'status' => 'pending', 'quantity' => 2, 'unit_price' => 2590050,
        'links' => ['https://shop.example.com/lenovo-e14'], 'requested_by_name' => 'Somchai Office',
    ])->and($pr->getMedia('attachments'))->toHaveCount(1);

    $this->actingAs($this->staff)->get("/purchase-requests/{$pr->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('request.total', '51801.00')
        ->has('attachments', 1)
        ->where('actions', ['cancel'])
        ->where('can.update', true));
});

it('requires what, how many, by when, why and at least one product link', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', [
        'unit' => 'เครื่อง', 'item_name' => '', 'quantity' => null, 'needed_by' => null, 'reason' => '', 'links' => ['', '  '],
    ])->assertSessionHasErrors(['item_name', 'quantity', 'needed_by', 'reason', 'links']);

    // a date in the past is not a date to need it by
    $this->actingAs($this->staff)->post('/purchase-requests', [...$this->payload, 'needed_by' => '2026-09-30'])->assertSessionHasErrors('needed_by');

    expect(PurchaseRequest::count())->toBe(0);
});

it('accepts web links only, and not too many', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', [...$this->payload, 'links' => ['javascript:alert(1)']])
        ->assertSessionHasErrors('links.0');
    $this->actingAs($this->staff)->post('/purchase-requests', [...$this->payload, 'links' => ['file:///etc/passwd']])
        ->assertSessionHasErrors('links.0');
    $this->actingAs($this->staff)->post('/purchase-requests', [...$this->payload, 'links' => array_fill(0, 6, 'https://a.example.com')])
        ->assertSessionHasErrors('links');
    $this->actingAs($this->staff)->post('/purchase-requests', [...$this->payload, 'quantity' => 0])->assertSessionHasErrors('quantity');

    expect(PurchaseRequest::count())->toBe(0);
});

it('shows staff their own requests, and approvers all of them', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', $this->payload);
    $mine = PurchaseRequest::sole();
    $colleague = userWithRole('technician');

    $this->actingAs($colleague)->get('/purchase-requests')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
    $this->actingAs($colleague)->get("/purchase-requests/{$mine->ulid}")->assertForbidden();
    $this->actingAs($this->admin)->get('/purchase-requests')->assertInertia(fn (Assert $page) => $page
        ->where('requests.total', 1)
        ->where('can.viewAll', true));
    $this->actingAs($this->admin)->get('/purchase-requests?search=accounting&status=pending')->assertInertia(fn (Assert $page) => $page->where('requests.total', 1));
    $this->actingAs($this->admin)->get('/purchase-requests?mine=1')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));

    // without purchase-requests.view, and customer accounts, never
    $this->actingAs(userWithRole('user'))->get('/purchase-requests')->assertForbidden();
    $client = userWithRole('customer_it', ['customer_id' => createCustomer()->id]);
    $this->actingAs($client)->get('/purchase-requests')->assertForbidden();
});

it('goes from approval to order to delivery, each by the right people', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', $this->payload);
    $pr = PurchaseRequest::sole();
    $move = fn ($user, string $action, ?string $note = null) => $this->actingAs($user)->post("/purchase-requests/{$pr->ulid}/move", ['action' => $action, 'note' => $note]);

    $move(userWithRole('technician'), 'approve')->assertForbidden();
    $move(userWithRole('helpdesk'), 'approve')->assertForbidden(); // sees all, but does not approve
    $move($this->staff, 'approve')->assertForbidden();
    $move($this->admin, 'order')->assertForbidden(); // not approved yet
    $move($this->admin, 'reject')->assertSessionHasErrors('note');

    $move($this->admin, 'approve')->assertSessionHasNoErrors();
    expect($pr->fresh()->only(['status', 'decided_by_name']))->toBe(['status' => 'approved', 'decided_by_name' => 'Admin Boss']);
    // no longer the requester's to change or withdraw
    $this->actingAs($this->staff)->get("/purchase-requests/{$pr->ulid}/edit")->assertForbidden();
    $move($this->staff, 'cancel')->assertForbidden();

    $move($this->admin, 'order', 'Supplier ABC, PO-123')->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/purchase-requests/{$pr->ulid}/receipts", ['quantity' => 2, 'item_kind' => 'part'])->assertSessionHasNoErrors();
    expect($pr->fresh()->only(['status', 'order_note']))->toBe(['status' => 'registered', 'order_note' => 'Supplier ABC, PO-123']);

    // what arrived can still be entered on the full asset form: name, price, date, one row per unit
    $this->actingAs($this->admin)->get("/assets/create?purchase_request={$pr->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('copy.name', 'Notebook for accounting')
        ->where('copy.purchase_price', '25900.50')
        ->where('copy.purchased_at', '2026-10-01')
        ->where('purchase', ['pr_no' => 'PR-2569-00001', 'quantity' => 2]));
});

it('lets the requester change or withdraw a request while it waits', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', $this->payload);
    $pr = PurchaseRequest::sole();

    $this->actingAs($this->staff)->put("/purchase-requests/{$pr->ulid}", [...$this->payload, 'quantity' => 3])->assertSessionHasNoErrors();
    expect($pr->fresh()->quantity)->toBe(3);
    $this->actingAs($this->admin)->put("/purchase-requests/{$pr->ulid}", $this->payload)->assertForbidden();

    $this->actingAs($this->staff)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'cancel'])->assertSessionHasNoErrors();
    expect($pr->fresh()->status)->toBe('cancelled');
});

it('prints the request with its links', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', $this->payload);
    $pr = PurchaseRequest::sole();

    $this->actingAs($this->staff)->get("/purchase-requests/{$pr->ulid}/print")->assertOk()
        ->assertSee('ใบขอซื้อ')
        ->assertSee('PR-2569-00001')
        ->assertSee('https://shop.example.com/lenovo-e14')
        ->assertSee('51,801.00');
});

it('keeps each company to its own requests', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', $this->payload);
    $pr = PurchaseRequest::sole();

    $otherAdmin = userWithRole('admin_company', [], createTenant('other'));
    $this->actingAs($otherAdmin)->get("/purchase-requests/{$pr->ulid}")->assertNotFound();
    $this->actingAs($otherAdmin)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'approve'])->assertNotFound();
    $this->actingAs($otherAdmin)->get('/purchase-requests')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
});

it('follows the scope of purchase-requests.view, also in the summary rows', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', $this->payload);
    $other = userWithRole('technician');
    $this->actingAs($other)->post('/purchase-requests', [...$this->payload, 'item_name' => 'Other']);

    // scope own: only the own request, no "mine" switch
    $this->actingAs($this->staff)->get('/purchase-requests')->assertInertia(fn (Assert $page) => $page
        ->where('requests.total', 1)->where('requests.data.0.item_name', 'Notebook for accounting')->where('can.viewAll', false));
    expect(app(PurchaseSummaryRows::class)->handle($this->staff)->count())->toBe(1);

    // scope all (helpdesk): everyone's, but changing stays with the requester
    $helpdesk = userWithRole('helpdesk');
    $this->actingAs($helpdesk)->get('/purchase-requests')->assertInertia(fn (Assert $page) => $page->where('requests.total', 2)->where('can.viewAll', true));
    expect(app(PurchaseSummaryRows::class)->handle($helpdesk)->count())->toBe(2);
    $pr = PurchaseRequest::where('item_name', 'Other')->sole();
    $this->actingAs($helpdesk)->get("/purchase-requests/{$pr->ulid}/edit")->assertForbidden();

    // a technician given scope all sees everyone's too
    setRoleScope('technician', 'all', ['purchase-requests.view']);
    $this->actingAs($this->staff)->get('/purchase-requests')->assertInertia(fn (Assert $page) => $page->where('requests.total', 2));
});

it('lets buyers with purchase-requests.receive order and receive an approved request', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', $this->payload);
    $pr = PurchaseRequest::sole();
    $this->actingAs($this->admin)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'approve'])->assertSessionHasNoErrors();

    $helpdesk = userWithRole('helpdesk');
    $this->actingAs($helpdesk)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'order'])->assertForbidden();
    grantTo('helpdesk', ['purchase-requests.receive']);
    $this->actingAs($helpdesk)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'order'])->assertSessionHasNoErrors();
    expect($pr->fresh()->status)->toBe('ordered');
});

it('asks for several items on one form: one request each, sharing the reason, date and quotation, in one batch', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', [
        ...$this->payload,
        'attachments' => [UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%%EOF")],
        'extra_items' => [
            ['item_name' => 'Mouse', 'quantity' => 2, 'unit' => 'ตัว', 'unit_price' => '350', 'links' => ['https://shop.example.com/mouse', ''], 'item_kind' => 'part'],
            ['item_name' => 'Keyboard', 'quantity' => 1, 'unit' => 'ตัว', 'links' => ['https://shop.example.com/keyboard']],
        ],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $requests = PurchaseRequest::orderBy('id')->get();
    expect($requests->pluck('item_name')->all())->toBe(['Notebook for accounting', 'Mouse', 'Keyboard'])
        ->and($requests->pluck('pr_no')->all())->toBe(['PR-2569-00001', 'PR-2569-00002', 'PR-2569-00003'])
        ->and($requests->pluck('batch')->unique()->count())->toBe(1)
        ->and($requests->first()->batch)->not->toBeNull()
        ->and($requests->pluck('reason')->unique()->all())->toBe(['New staff'])
        ->and($requests[1]->only(['unit_price', 'links', 'item_kind']))->toBe(['unit_price' => 35000, 'links' => ['https://shop.example.com/mouse'], 'item_kind' => 'part'])
        ->and($requests->map(fn ($pr) => $pr->getMedia('attachments')->count())->all())->toBe([1, 1, 1]);

    $this->actingAs($this->staff)->get("/purchase-requests/{$requests[1]->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('batch.items', fn ($items) => collect($items)->pluck('pr_no')->all() === ['PR-2569-00001', 'PR-2569-00002', 'PR-2569-00003']
            && collect($items)->firstWhere('current', true)['item_name'] === 'Mouse')
        // the requester may not decide on them
        ->where('batch.decidable', ['approve' => 0, 'reject' => 0]));
});

it('checks every extra item, and asks for one alone without a batch', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', [...$this->payload, 'extra_items' => [
        ['item_name' => '', 'quantity' => 0, 'unit' => 'ตัว', 'links' => ['javascript:alert(1)']],
    ]])->assertSessionHasErrors(['extra_items.0.item_name', 'extra_items.0.quantity', 'extra_items.0.links.0']);
    expect(PurchaseRequest::count())->toBe(0);

    $this->actingAs($this->staff)->post('/purchase-requests', $this->payload)->assertSessionHasNoErrors();
    $pr = PurchaseRequest::sole();
    expect($pr->batch)->toBeNull();
    $this->actingAs($this->staff)->get("/purchase-requests/{$pr->ulid}")->assertInertia(fn (Assert $page) => $page->where('batch', null));

    // An edit changes that one request only.
    $this->actingAs($this->staff)->put("/purchase-requests/{$pr->ulid}", [...$this->payload, 'extra_items' => [['item_name' => 'x']]])
        ->assertSessionHasErrors('extra_items');
});

it('lets the approver approve or turn down a whole batch in one go', function () {
    $this->actingAs($this->staff)->post('/purchase-requests', [...$this->payload, 'extra_items' => [
        ['item_name' => 'Mouse', 'quantity' => 2, 'unit' => 'ตัว', 'links' => ['https://shop.example.com/mouse']],
        ['item_name' => 'Keyboard', 'quantity' => 1, 'unit' => 'ตัว', 'links' => ['https://shop.example.com/keyboard']],
    ]])->assertSessionHasNoErrors();
    [$first, $second, $third] = PurchaseRequest::orderBy('id')->get()->all();

    // One turned down on its own first; the batch decision leaves it as it is.
    $this->actingAs($this->admin)->post("/purchase-requests/{$third->ulid}/move", ['action' => 'reject', 'note' => 'Have spares'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->get("/purchase-requests/{$first->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('batch.decidable', ['approve' => 2, 'reject' => 2]));

    $this->actingAs($this->staff)->post("/purchase-requests/{$first->ulid}/move-batch", ['action' => 'approve'])->assertForbidden();
    $this->actingAs($this->admin)->post("/purchase-requests/{$first->ulid}/move-batch", ['action' => 'reject'])->assertSessionHasErrors('note');
    $this->actingAs($this->admin)->post("/purchase-requests/{$first->ulid}/move-batch", ['action' => 'approve'])->assertSessionHasNoErrors();

    expect(PurchaseRequest::orderBy('id')->pluck('status')->all())->toBe(['approved', 'approved', 'rejected'])
        ->and($second->fresh()->decided_by_name)->toBe('Admin Boss');

    // Nothing left to decide.
    $this->actingAs($this->admin)->post("/purchase-requests/{$first->ulid}/move-batch", ['action' => 'approve'])->assertSessionHasErrors('action');

    // Another company never reaches the batch.
    $other = userWithRole('admin_company', [], createTenant('other'));
    $this->actingAs($other)->post("/purchase-requests/{$first->ulid}/move-batch", ['action' => 'approve'])->assertNotFound();
});
