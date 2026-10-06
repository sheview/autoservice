<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Jobs\DeliverAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Purchase requests once approved: deliveries in parts that go into the system as they are
 * received (an asset of the category the request says, or stock of a part), handed to whoever
 * asked in one go or on an issue/loan request of their own (no second approval), the history of
 * every step, the papers, the alerts, and who may do what.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->buyer = userWithRole('purchasing', ['name' => 'Buyer Ploy']);
    $this->staff = userWithRole('technician', ['name' => 'Somchai Tech']);
    $this->notebooks = createAssetCategory(['name' => 'Notebook', 'requires_serial' => true]);
    $this->payload = [
        'item_name' => 'Notebook Lenovo E14', 'quantity' => 3, 'unit' => 'เครื่อง', 'unit_price' => '25000',
        'links' => ['https://shop.example.com/e14'], 'reason' => 'New staff', 'needed_by' => '2026-10-20',
        'item_kind' => 'asset', 'asset_category_id' => $this->notebooks->id,
    ];
    // Asked by the technician and approved (ordering is optional), or also marked ordered.
    $this->approved = function (array $data = [], bool $order = false) {
        $this->actingAs($this->staff)->post('/purchase-requests', [...$this->payload, ...$data])->assertSessionHasNoErrors();
        $pr = PurchaseRequest::latest('id')->first();
        $this->actingAs($this->admin)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'approve'])->assertSessionHasNoErrors();
        if ($order) {
            $this->actingAs($this->buyer)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'order', 'note' => 'PO-1'])->assertSessionHasNoErrors();
        }

        return $pr->fresh();
    };
    $this->receive = fn (PurchaseRequest $pr, array $data, $user = null) => $this->actingAs($user ?? $this->buyer)->post("/purchase-requests/{$pr->ulid}/receipts", $data);
});

it('takes deliveries in parts, never more than asked for, putting each into the system as it comes', function () {
    $pr = ($this->approved)(order: true);

    ($this->receive)($pr, ['quantity' => 4])->assertSessionHasErrors('quantity');
    ($this->receive)($pr, ['quantity' => 1, 'serials' => "SN1\nSN2"])->assertSessionHasErrors('serials');

    ($this->receive)($pr, ['quantity' => 2, 'brand' => 'Lenovo', 'model' => 'E14 Gen 5', 'unit_price' => '24500.50', 'serials' => "SN1\n\nSN2\n", 'location' => 'Store room'])
        ->assertSessionHasNoErrors();
    // a category counted by serial: each device an asset of its own, with its own code
    $assets = Asset::where('name', 'Notebook Lenovo E14')->orderBy('id')->get();
    expect($pr->fresh()->only(['status', 'qty_received', 'qty_registered']))->toBe(['status' => 'partially_received', 'qty_received' => 2, 'qty_registered' => 2])
        ->and(PurchaseReceipt::sole()->only(['quantity', 'brand', 'model', 'unit_price', 'serials', 'received_by_name', 'registered_as']))->toBe([
            'quantity' => 2, 'brand' => 'Lenovo', 'model' => 'E14 Gen 5', 'unit_price' => 2450050, 'serials' => ['SN1', 'SN2'],
            'received_by_name' => 'Buyer Ploy', 'registered_as' => 'asset',
        ])
        ->and(PurchaseReceipt::sole()->assets()->pluck('asset_id')->all())->toBe($assets->pluck('id')->all())
        ->and($assets)->toHaveCount(2)
        ->and($assets->pluck('asset_code')->unique())->toHaveCount(2)
        ->and($assets->map(fn (Asset $a) => $a->serials()->pluck('serial_number')->all())->all())->toBe([['SN1'], ['SN2']])
        ->and($assets[0]->only(['brand', 'model', 'quantity', 'status', 'purchase_price', 'location', 'category_id']))->toBe([
            'brand' => 'Lenovo', 'model' => 'E14 Gen 5', 'quantity' => 1, 'status' => 'spare', 'purchase_price' => 2450050,
            'location' => 'Store room', 'category_id' => $this->notebooks->id,
        ]);

    ($this->receive)($pr, ['quantity' => 2])->assertSessionHasErrors('quantity'); // only 1 left
    // a category counted by serial needs one for every unit: nothing is recorded without it
    ($this->receive)($pr, ['quantity' => 1])->assertSessionHasErrors('category_id');
    expect(PurchaseReceipt::count())->toBe(1);
    ($this->receive)($pr, ['quantity' => 1, 'serials' => ['SN3']])->assertSessionHasNoErrors();
    expect($pr->fresh()->only(['status', 'qty_received', 'qty_registered']))->toBe(['status' => 'registered', 'qty_received' => 3, 'qty_registered' => 3]);
    ($this->receive)($pr, ['quantity' => 1])->assertForbidden(); // nothing more to come

    // every step is in the history, with who and when
    $this->actingAs($this->staff)->get("/purchase-requests/{$pr->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('receipts', 2)
        ->where('events', fn ($events) => collect($events)->pluck('action')->all() === ['create', 'approve', 'order', 'receive', 'register', 'receive', 'register'])
        ->where('events.3.to_status', 'partially_received')
        ->where('events.3.actor_name', 'Buyer Ploy')
        ->where('events.6.to_status', 'registered'));
});

it('takes a delivery straight after approval, asking what it becomes when the request does not say', function () {
    $pr = ($this->approved)(['item_kind' => null, 'asset_category_id' => null, 'quantity' => 2]);
    expect($pr->status)->toBe('approved');

    ($this->receive)($pr, ['quantity' => 1])->assertSessionHasErrors('item_kind');
    ($this->receive)($pr, ['quantity' => 1, 'item_kind' => 'asset'])->assertSessionHasErrors('asset_category_id');

    $accessories = createAssetCategory(['name' => 'Accessory']);
    ($this->receive)($pr, ['quantity' => 1, 'item_kind' => 'asset', 'asset_category_id' => $accessories->id])->assertSessionHasNoErrors();
    // said once, kept for the next delivery
    expect($pr->fresh()->only(['status', 'item_kind', 'asset_category_id']))->toBe(['status' => 'partially_received', 'item_kind' => 'asset', 'asset_category_id' => $accessories->id]);
    ($this->receive)($pr, ['quantity' => 1])->assertSessionHasNoErrors();
    expect($pr->fresh()->status)->toBe('registered')
        ->and(Asset::where('category_id', $accessories->id)->count())->toBe(2);
});

it('puts parts into stock of the part of the same name, or of a new part with the next code', function () {
    $ram = createPart(['code' => 'RAM8', 'name' => 'RAM 8GB', 'unit' => 'แผง'], stock: 1);
    createPart(['code' => 'PT-00007', 'name' => 'Old']);
    $pr = ($this->approved)(['item_name' => 'ram 8gb', 'unit' => 'แผง', 'quantity' => 5, 'item_kind' => 'part', 'asset_category_id' => null]);

    ($this->receive)($pr, ['quantity' => 3, 'unit_price' => '650'])->assertSessionHasNoErrors();
    expect($ram->fresh()->only(['qty_on_hand', 'unit_cost']))->toBe(['qty_on_hand' => 4, 'unit_cost' => 65000])
        ->and($ram->movements()->latest('id')->first()->reference)->toBe($pr->pr_no);
    // or the part chosen
    $other = createPart(['code' => 'RAM8-B', 'name' => 'RAM 8GB (spare)', 'unit' => 'แผง']);
    ($this->receive)($pr, ['quantity' => 2, 'part_id' => $other->id])->assertSessionHasNoErrors();
    expect($other->fresh()->qty_on_hand)->toBe(2)
        ->and($pr->fresh()->status)->toBe('registered');

    $ssd = ($this->approved)(['item_name' => 'SSD 1TB', 'unit' => 'ลูก', 'quantity' => 2, 'item_kind' => 'part', 'asset_category_id' => null]);
    ($this->receive)($ssd, ['quantity' => 1])->assertSessionHasNoErrors();
    ($this->receive)($ssd, ['quantity' => 1])->assertSessionHasNoErrors();
    expect(Part::where('name', 'SSD 1TB')->sole()->only(['code', 'qty_on_hand']))->toBe(['code' => 'PT-00008', 'qty_on_hand' => 2]);
});

it('receives and hands over to whoever asked in one go, with the papers to print', function () {
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 dn', 200, ['Content-Type' => 'application/pdf'])]);
    $pr = ($this->approved)(['quantity' => 2, 'contract_id' => createContract(createCustomer(), ['contract_no' => 'MA-9', 'title' => 'Bank'])->id]);

    ($this->receive)($pr, ['quantity' => 2, 'serials' => "SN-A\nSN-B", 'hand_out' => true])->assertSessionHasNoErrors();

    $checkout = CheckoutRequest::sole();
    expect($checkout->only(['status', 'borrower_user_id', 'contract_id', 'auto_approved']))->toBe([
        'status' => 'closed', 'borrower_user_id' => $this->staff->id, 'contract_id' => $pr->contract_id, 'auto_approved' => true,
    ])
        // one line per device, each handed out
        ->and($checkout->items()->get(['qty_fulfilled', 'purchase_request_id'])->toArray())->toBe([
            ['qty_fulfilled' => 1, 'purchase_request_id' => $pr->id], ['qty_fulfilled' => 1, 'purchase_request_id' => $pr->id],
        ])
        ->and($pr->fresh()->only(['status', 'qty_issued']))->toBe(['status' => 'issued', 'qty_issued' => 2]);

    $this->actingAs($this->buyer)->get("/checkout-requests/{$checkout->ulid}/delivery-note/print")->assertOk()
        ->assertSee('ใบส่งสินค้า')
        ->assertSee($checkout->request_no)
        ->assertSee('SN-A')
        ->assertSee('SN-B')
        ->assertSee($pr->pr_no)
        ->assertSee('MA-9 Bank')
        ->assertSee('Buyer Ploy')
        ->assertSee('2569'); // Buddhist year on documents
    $this->actingAs($this->buyer)->get("/checkout-requests/{$checkout->ulid}/delivery-note")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($this->staff)->get("/purchase-requests/{$pr->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('checkouts.0.printable', true)
        ->where('checkouts.0.delivered', true));
    // someone who may not see the request gets no paper
    $this->actingAs(userWithRole('user'))->get("/checkout-requests/{$checkout->ulid}/delivery-note")->assertForbidden();
});

it('hands over later what was received without it, and only by whoever hands things out', function () {
    $pr = ($this->approved)(['quantity' => 1]);
    ($this->receive)($pr, ['quantity' => 1, 'serials' => 'SN1'])->assertSessionHasNoErrors();
    expect(CheckoutRequest::count())->toBe(0);

    $this->actingAs($this->buyer)->get("/purchase-requests/{$pr->ulid}")->assertInertia(fn (Assert $page) => $page->where('can.handOut', true));
    $this->actingAs($this->staff)->post("/purchase-requests/{$pr->ulid}/hand-out")->assertForbidden();
    $this->actingAs($this->buyer)->post("/purchase-requests/{$pr->ulid}/hand-out")->assertSessionHasNoErrors();
    expect($pr->fresh()->status)->toBe('issued');
    $this->actingAs($this->buyer)->post("/purchase-requests/{$pr->ulid}/hand-out")->assertSessionHasErrors('hand_out'); // nothing left
});

it('ties the purchase to the issue/loan draft it was asked from, and hands it out from there with no second approval', function () {
    $spare = createAsset(createAssetCategory(['name' => 'Mouse']), ['name' => 'Mouse', 'status' => Asset::STATUS_SPARE]);

    // nothing to hand out was found: the draft is kept and the purchase request opens for it
    $this->actingAs($this->staff)->post('/checkout-requests', [
        'purpose' => 'New staff', 'submit' => true, 'then_purchase' => 'Notebook Lenovo E14',
        'items' => [['item_type' => 'asset', 'asset_id' => $spare->id, 'qty' => 1, 'checkout_type' => 'issue']],
    ])->assertRedirect();
    $draft = CheckoutRequest::sole();
    expect($draft->status)->toBe('draft');
    $this->actingAs($this->staff)->get("/purchase-requests/create?item=Notebook&checkout={$draft->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('item', 'Notebook')->where('source.request_no', $draft->request_no));

    $pr = ($this->approved)(['checkout_request_id' => $draft->id, 'quantity' => 2]);
    ($this->receive)($pr, ['quantity' => 2, 'serials' => "SN1\nSN2"])->assertSessionHasNoErrors();

    // the requester continues their draft with what was bought
    $this->actingAs($this->staff)->get("/purchase-requests/{$pr->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('checkouts.0.request_no', $draft->request_no)
        ->where('checkouts.0.source', true)
        ->where('issueUrl', route('asset.requests.edit', [$draft->ulid, 'purchase_request' => $pr->ulid])));
    [$first, $second] = Asset::where('name', 'Notebook Lenovo E14')->orderBy('id')->get()->all();
    $this->actingAs($this->staff)->get("/checkout-requests/{$draft->ulid}/edit?purchase_request={$pr->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('purchase.pr_no', $pr->pr_no)
        ->has('purchase.items', 2)
        ->where('purchase.items.0.asset_id', $first->id)
        ->where('purchase.items.1.asset_id', $second->id)
        ->where('purchase.items.0.qty', 1)
        ->where('purchase.items.0.purchase_request_id', $pr->id));

    // with a line that is not from the purchase it still waits for an approver
    $lines = [
        ['item_type' => 'asset', 'asset_id' => $spare->id, 'qty' => 1, 'checkout_type' => 'issue'],
        ['item_type' => 'asset', 'asset_id' => $first->id, 'qty' => 1, 'checkout_type' => 'issue', 'purchase_request_id' => $pr->id],
        ['item_type' => 'asset', 'asset_id' => $second->id, 'qty' => 1, 'checkout_type' => 'issue', 'purchase_request_id' => $pr->id],
    ];
    $this->actingAs($this->staff)->put("/checkout-requests/{$draft->ulid}", ['purpose' => 'New staff', 'submit' => true, 'items' => $lines])->assertSessionHasNoErrors();
    expect($draft->fresh()->status)->toBe('pending');
    $this->actingAs($this->admin)->post("/checkout-requests/{$draft->ulid}/approve")->assertSessionHasNoErrors();

    // handed out one device at a time, the second first: issued once all of it is out
    $this->actingAs($this->buyer)->post('/checkout-items/'.$draft->items()->where('asset_id', $second->id)->sole()->id.'/fulfill', ['qty' => 1])->assertSessionHasNoErrors();
    expect($pr->fresh()->only(['status', 'qty_issued']))->toBe(['status' => 'registered', 'qty_issued' => 1]);
    $this->actingAs($this->buyer)->post('/checkout-items/'.$draft->items()->where('asset_id', $first->id)->sole()->id.'/fulfill', ['qty' => 1])->assertSessionHasNoErrors();
    expect($pr->fresh()->only(['status', 'qty_issued']))->toBe(['status' => 'issued', 'qty_issued' => 2])
        ->and($pr->events()->pluck('action')->last())->toBe('issue');
});

it('approves at once a request of only what an approved purchase bought', function () {
    $pr = ($this->approved)(['quantity' => 1]);
    ($this->receive)($pr, ['quantity' => 1, 'serials' => 'SN1']);
    $notebook = Asset::where('name', 'Notebook Lenovo E14')->sole();
    $send = fn (int $qty) => $this->actingAs($this->buyer)->post('/checkout-requests', [
        'borrower_user_id' => $this->staff->id, 'purpose' => 'x', 'submit' => true,
        'items' => [['item_type' => 'asset', 'asset_id' => $notebook->id, 'qty' => $qty, 'checkout_type' => 'issue', 'purchase_request_id' => $pr->id]],
    ]);

    $send(1)->assertSessionHasNoErrors();
    expect(CheckoutRequest::sole()->only(['status', 'auto_approved']))->toBe(['status' => 'approved', 'auto_approved' => true]);
});

it('announces each step where the company set its alerts, with who, what, how many, which project and by when', function () {
    Queue::fake();
    app(SaveAlertSettings::class)->handle($this->tenant, [
        'events' => ['purchase_requested', 'purchase_received'],
        'line' => ['enabled' => true, 'to' => 'Cgroup', 'token' => 'line-token'],
        'telegram' => ['enabled' => false, 'chat_id' => '', 'token' => ''],
        'mail' => ['enabled' => false, 'recipients' => []],
    ]);
    $contract = createContract(createCustomer(), ['contract_no' => 'MA-2026-007', 'title' => 'Bank network']);

    $pr = ($this->approved)(['contract_id' => $contract->id, 'item_kind' => 'part', 'asset_category_id' => null]);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'purchase_requested'
        && str_contains($job->title, $pr->pr_no)
        && str_contains($job->body, 'Somchai Tech') && str_contains($job->body, 'Notebook Lenovo E14')
        && str_contains($job->body, '3 เครื่อง') && str_contains($job->body, 'MA-2026-007 Bank network')
        && str_contains($job->body, '20/10/2026')
        && $job->url === route('inventory.purchase-requests.show', $pr));
    // not wanted by the company
    Queue::assertNotPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'purchase_approved');

    ($this->receive)($pr, ['quantity' => 1]);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'purchase_received' && str_contains($job->body, '1 เครื่อง'));
});

it('lets only buyers take deliveries, and cancel an order until something arrives', function () {
    $pr = ($this->approved)(['item_kind' => 'part', 'asset_category_id' => null], order: true);

    ($this->receive)($pr, ['quantity' => 1], $this->staff)->assertForbidden();
    ($this->receive)($pr, ['quantity' => 1], userWithRole('helpdesk'))->assertForbidden();
    // buyers do not decide on requests
    $this->actingAs($this->buyer)->post('/purchase-requests', $this->payload);
    $own = PurchaseRequest::latest('id')->first();
    $this->actingAs($this->buyer)->post("/purchase-requests/{$own->ulid}/move", ['action' => 'approve'])->assertForbidden();

    ($this->receive)($pr, ['quantity' => 1])->assertSessionHasNoErrors();
    // once something arrived it is no longer cancelled
    $this->actingAs($this->buyer)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'cancel'])->assertForbidden();

    $other = ($this->approved)(order: true);
    $this->actingAs($this->staff)->post("/purchase-requests/{$other->ulid}/move", ['action' => 'cancel'])->assertForbidden();
    $this->actingAs($this->buyer)->post("/purchase-requests/{$other->ulid}/move", ['action' => 'cancel', 'note' => 'Out of stock'])->assertSessionHasNoErrors();
    expect($other->fresh()->status)->toBe('cancelled')
        ->and($other->events()->pluck('action')->last())->toBe('cancel');
});

it('offers to hand out only the devices still free, whichever went out first', function () {
    $pr = ($this->approved)(['quantity' => 2]);
    ($this->receive)($pr, ['quantity' => 2, 'serials' => "SN1\nSN2"]);
    [$first, $second] = Asset::where('name', 'Notebook Lenovo E14')->orderBy('id')->get()->all();

    // the second device goes out on a request of its own
    $this->actingAs($this->buyer)->post('/checkout-requests', [
        'borrower_user_id' => $this->staff->id, 'purpose' => 'x', 'submit' => true,
        'items' => [['item_type' => 'asset', 'asset_id' => $second->id, 'qty' => 1, 'checkout_type' => 'issue', 'purchase_request_id' => $pr->id]],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->buyer)->post('/checkout-items/'.CheckoutRequest::sole()->items()->sole()->id.'/fulfill', ['qty' => 1]);

    $this->actingAs($this->buyer)->get("/checkout-requests/create?purchase_request={$pr->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('purchase.items', 1)
        ->where('purchase.items.0.asset_id', $first->id));
});

it('registers on its own a delivery recorded before deliveries went into the system by themselves', function () {
    $pr = ($this->approved)(['quantity' => 1, 'item_kind' => null, 'asset_category_id' => null], order: true);
    // as the migration left the ones received before
    $receipt = $pr->receipts()->create(['quantity' => 1, 'received_by_name' => 'Old', 'received_at' => now()]);
    $pr->update(['qty_received' => 1, 'status' => 'received']);

    $register = fn (array $data, $user = null) => $this->actingAs($user ?? $this->buyer)->post("/purchase-requests/{$pr->ulid}/receipts/{$receipt->id}/register", $data);
    $register(['as' => 'part'], $this->staff)->assertForbidden();
    $register(['as' => 'part', 'part_code' => 'NB-1'])->assertSessionHasNoErrors();
    $register(['as' => 'part'])->assertForbidden(); // nothing left to register
    expect(Part::where('code', 'NB-1')->sole()->qty_on_hand)->toBe(1)
        ->and($pr->fresh()->status)->toBe('registered');
});

it('gives the buyers their queues: to order, to receive, to hand out', function () {
    $ordered = ($this->approved)(['item_kind' => 'part', 'asset_category_id' => null], order: true);
    $approved = ($this->approved)();
    ($this->receive)($ordered, ['quantity' => 1]);

    $this->actingAs($this->buyer)->get('/purchase-requests?queue=to_receive')->assertInertia(fn (Assert $page) => $page
        ->where('queues', ['to_order' => 1, 'to_receive' => 1, 'to_register' => 0, 'to_issue' => 1])
        ->where('requests.total', 1)
        ->where('requests.data.0.pr_no', $ordered->pr_no));
    $this->actingAs($this->buyer)->get('/purchase-requests?queue=to_order')
        ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.pr_no', $approved->pr_no));
    // a technician has no queues, only their own requests
    $this->actingAs($this->staff)->get('/purchase-requests?queue=to_order')
        ->assertInertia(fn (Assert $page) => $page->where('queues', [])->where('filters.queue', null));
});

it('offers a superadmin inside the company only what the state allows', function () {
    $pr = ($this->approved)(['quantity' => 1, 'item_kind' => 'part', 'asset_category_id' => null]);
    ($this->receive)($pr, ['quantity' => 1]);
    $superadmin = createSuperadmin();
    $this->actingAs($superadmin)->post("/platform/impersonation/{$this->tenant->ulid}");

    // all of it is here and in the system: nothing to receive, register or move
    $this->actingAs($superadmin)->get("/purchase-requests/{$pr->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('can.receive', false)
        ->where('can.register', false)
        ->where('actions', []));
    ($this->receive)($pr, ['quantity' => 1], $superadmin)->assertSessionHasErrors('quantity');
});

it('keeps deliveries inside the company', function () {
    $pr = ($this->approved)(['item_kind' => 'part', 'asset_category_id' => null]);
    ($this->receive)($pr, ['quantity' => 1]);
    $receipt = PurchaseReceipt::sole();

    $other = userWithRole('admin_company', [], createTenant('other'));
    ($this->receive)($pr, ['quantity' => 1], $other)->assertNotFound();
    $this->actingAs($other)->post("/purchase-requests/{$pr->ulid}/hand-out")->assertNotFound();
    $this->actingAs($other)->post("/purchase-requests/{$pr->ulid}/receipts/{$receipt->id}/register", ['as' => 'part'])->assertNotFound();
    expect(asTenant(createTenant('third'), fn () => PurchaseReceipt::count()))->toBe(0);
});
