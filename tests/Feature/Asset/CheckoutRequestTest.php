<?php

use App\Modules\Asset\Actions\AssetHeldQuantities;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Issue/loan requests with several lines (assets and parts): draft, send, approve (lines cut down
 * or rejected with a reason), hand out line by line (backorders, purchase requests, restock),
 * return, close. Delay alerts, permissions and the list tabs are in CheckoutRequestAccessTest.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    // helpdesk: asset-checkouts.create (for anyone), fulfill and return; not approve
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->staff = userWithRole('user', ['name' => 'Somchai Office']);
    $category = createAssetCategory(['name' => 'Notebook']);
    $this->notebook = createAsset($category, ['name' => 'Notebook Lenovo', 'brand' => 'Lenovo', 'model' => 'E14', 'status' => Asset::STATUS_SPARE]);
    $this->cables = createAsset(createAssetCategory(['name' => 'Cable']), ['name' => 'LAN cable', 'quantity' => 10, 'unit' => 'เส้น', 'status' => Asset::STATUS_SPARE]);
    $this->ram = createPart(['code' => 'RAM8', 'name' => 'RAM 8GB', 'unit' => 'แผง', 'unit_cost' => 50000], stock: 5);
    $this->ticket = openTicket($this->desk, ['title' => 'PC slow']);

    $this->asset = fn (Asset $asset, array $line = []) => $line + ['item_type' => 'asset', 'asset_id' => $asset->id, 'qty' => 1, 'checkout_type' => 'issue'];
    $this->part = fn (int $qty, array $line = []) => $line + ['item_type' => 'part', 'part_id' => $this->ram->id, 'qty' => $qty];
    // Sends a request as helpdesk for the staff member (submit = false keeps it a draft).
    $this->send = fn (array $items, array $data = [], $user = null) => $this->actingAs($user ?? $this->desk)->post('/checkout-requests', $data + [
        'borrower_user_id' => $this->staff->id, 'purpose' => 'New staff', 'needed_by' => '2026-10-10', 'submit' => true, 'items' => $items,
    ]);
    $this->approved = function (array $items, array $data = []) {
        ($this->send)($items, $data)->assertSessionHasNoErrors();
        $request = CheckoutRequest::latest('id')->first();
        $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve")->assertSessionHasNoErrors();

        return $request->fresh();
    };
});

it('saves a draft, edits it, then sends it for approval', function () {
    ($this->send)([($this->asset)($this->notebook, ['checkout_type' => 'loan', 'due_return_date' => '2026-10-15'])], ['submit' => false])
        ->assertSessionHasNoErrors();

    $request = CheckoutRequest::sole();
    expect($request->only(['request_no', 'status', 'borrower_user_id', 'borrower_name', 'requester_name']))->toBe([
        'request_no' => 'CR-2569-00001', 'status' => 'draft', 'borrower_user_id' => $this->staff->id,
        'borrower_name' => 'Somchai Office', 'requester_name' => 'Desk One',
    ])
        ->and($request->items()->sole()->only(['item_type', 'item_name', 'checkout_type', 'qty_requested', 'status']))->toBe([
            'item_type' => 'asset', 'item_name' => 'Notebook Lenovo', 'checkout_type' => 'loan', 'qty_requested' => 1, 'status' => 'pending',
        ])
        // a draft holds nothing yet
        ->and(app(AssetHeldQuantities::class)->handle([$this->notebook->id]))->toBe([]);

    // the writer edits it: the lines are replaced
    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Asset/Requests/Form')->where('request.request_no', 'CR-2569-00001'));
    $this->actingAs($this->desk)->put("/checkout-requests/{$request->ulid}", [
        'borrower_name' => 'Contractor Lek', 'items' => [($this->asset)($this->cables, ['qty' => 3]), ($this->asset)($this->notebook)],
    ])->assertSessionHasNoErrors();
    expect($request->fresh()->only(['status', 'borrower_user_id', 'borrower_name']))->toBe(['status' => 'draft', 'borrower_user_id' => null, 'borrower_name' => 'Contractor Lek'])
        ->and($request->items()->pluck('qty_requested', 'item_name')->all())->toBe(['LAN cable' => 3, 'Notebook Lenovo' => 1]);

    // nobody else edits a draft, nor sees it
    $this->actingAs($this->admin)->get("/checkout-requests/{$request->ulid}/edit")->assertForbidden();
    $this->actingAs($this->admin)->get('/checkout-requests')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));

    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/submit")->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('pending')
        ->and($request->fresh()->submitted_at)->not->toBeNull()
        ->and(app(AssetHeldQuantities::class)->handle([$this->cables->id, $this->notebook->id]))->toEqual([$this->cables->id => 3, $this->notebook->id => 1]);

    // once sent it is no longer edited
    $this->actingAs($this->desk)->put("/checkout-requests/{$request->ulid}", ['borrower_name' => 'X', 'items' => [($this->asset)($this->notebook)]])->assertForbidden();
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/submit")->assertForbidden();
});

it('offers a purchase request on the form to those who may open one', function () {
    $this->actingAs($this->desk)->get('/checkout-requests/create')
        ->assertInertia(fn (Assert $page) => $page->component('Asset/Requests/Form')->where('canPurchase', true));
    $this->actingAs($this->staff)->get('/checkout-requests/create')
        ->assertInertia(fn (Assert $page) => $page->where('canPurchase', false));

    // The button leads to the purchase request form, started with the item.
    $this->actingAs($this->desk)->get('/purchase-requests/create?item='.urlencode('RAM 16GB'))
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/PurchaseRequests/Form')->where('item', 'RAM 16GB'));
});

it('checks the lines: parts need the ticket, loans a due date, assets enough left', function () {
    ($this->send)([($this->part)(2)])->assertSessionHasErrors('ticket_id');
    ($this->send)([($this->asset)($this->notebook, ['checkout_type' => 'loan'])])->assertSessionHasErrors('items.0');
    ($this->send)([])->assertSessionHasErrors('items');
    ($this->send)([($this->part)(2)], ['ticket_id' => 999999])->assertSessionHasErrors('ticket_id');
    expect(CheckoutRequest::count())->toBe(0);

    // a part line with the ticket is fine, even short of stock (backordered when handed out)
    ($this->send)([($this->part)(8)], ['ticket_id' => $this->ticket->id])->assertSessionHasNoErrors();
    expect(CheckoutItem::sole()->only(['item_type', 'item_code', 'unit', 'checkout_type', 'qty_requested']))->toBe([
        'item_type' => 'part', 'item_code' => 'RAM8', 'unit' => 'แผง', 'checkout_type' => 'issue', 'qty_requested' => 8,
    ]);

    // an asset under repair cannot be asked for
    $this->notebook->update(['status' => Asset::STATUS_IN_REPAIR]);
    ($this->send)([($this->asset)($this->notebook)])->assertSessionHasErrors('items.0');
});

it('takes a single asset as one, and a lot by quantity, holding what is asked for', function () {
    ($this->send)([($this->asset)($this->notebook, ['qty' => 3]), ($this->asset)($this->cables, ['qty' => 4])])->assertSessionHasNoErrors();
    expect(CheckoutItem::pluck('qty_requested', 'item_name')->all())->toBe(['Notebook Lenovo' => 1, 'LAN cable' => 4]);

    // what is asked for is held: the notebook is taken, 6 cables are left
    ($this->send)([($this->asset)($this->notebook)])->assertSessionHasErrors('items.0');
    ($this->send)([($this->asset)($this->cables, ['qty' => 7])])->assertSessionHasErrors('items.0');
    // the same asset twice in one request counts as one
    ($this->send)([($this->asset)($this->cables, ['qty' => 4]), ($this->asset)($this->cables, ['qty' => 3])])->assertSessionHasErrors('items.1');
    ($this->send)([($this->asset)($this->cables, ['qty' => 6])])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/assets?sort=name')->assertInertia(fn (Assert $page) => $page
        ->where('assets.data', fn ($rows) => collect($rows)->pluck('available', 'name')->all() === ['LAN cable' => 0, 'Notebook Lenovo' => 0]));
    $this->actingAs($this->admin)->get("/assets/{$this->cables->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('asset.available', 0)
        ->where('checkouts.available', false)
        ->where('checkouts.available_quantity', 0)
        ->where('checkouts.quantity', 10)
        ->where('checkouts.unit', 'เส้น')
        ->has('checkouts.lines', 2)
        ->where('checkouts.lines.0.request.request_no', 'CR-2569-00001')
        ->where('checkouts.lines.0.qty_requested', 4)
        ->where('checkouts.lines.0.request.borrower_name', 'Somchai Office')
        ->where('checkouts.can.create', true));

    // a rejected request holds nothing any more
    $first = CheckoutRequest::where('request_no', 'CR-2569-00001')->sole();
    $this->actingAs($this->admin)->post("/checkout-requests/{$first->ulid}/reject", ['reject_reason' => 'Not now'])->assertSessionHasNoErrors();
    expect(app(AssetHeldQuantities::class)->handle([$this->cables->id, $this->notebook->id]))->toBe([$this->cables->id => 6]);
});

it('approves everything as asked, or lowers and rejects lines with a reason', function () {
    ($this->send)([($this->asset)($this->cables, ['qty' => 5]), ($this->asset)($this->notebook), ($this->part)(3)], ['ticket_id' => $this->ticket->id]);
    $request = CheckoutRequest::sole();
    [$cables, $notebook, $ram] = $request->items->all();

    // helpdesk hands out but does not approve
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/approve")->assertForbidden();

    $approve = fn (array $lines) => $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve", ['lines' => $lines]);
    $approve([$notebook->id => ['qty' => 0]])->assertSessionHasErrors("lines.{$notebook->id}.reject_reason");
    $approve([$cables->id => ['qty' => 6]])->assertSessionHasErrors("lines.{$cables->id}.qty");
    expect($request->fresh()->status)->toBe('pending');

    $approve([
        $cables->id => ['qty' => 2],
        $notebook->id => ['qty' => 0, 'reject_reason' => 'Nothing spare'],
    ])->assertSessionHasNoErrors();

    expect($request->fresh()->only(['status', 'approved_by_name', 'auto_approved']))->toBe(['status' => 'approved', 'approved_by_name' => 'Admin Boss', 'auto_approved' => false])
        ->and($cables->fresh()->only(['status', 'qty_approved']))->toBe(['status' => 'approved', 'qty_approved' => 2])
        ->and($notebook->fresh()->only(['status', 'qty_approved', 'reject_reason']))->toBe(['status' => 'rejected', 'qty_approved' => 0, 'reject_reason' => 'Nothing spare'])
        ->and($ram->fresh()->only(['status', 'qty_approved']))->toBe(['status' => 'approved', 'qty_approved' => 3])
        // the lowered quantity is all that is held now
        ->and(app(AssetHeldQuantities::class)->handle([$this->cables->id, $this->notebook->id]))->toBe([$this->cables->id => 2]);

    // decided once only
    $approve([])->assertSessionHasErrors('request');
});

it('rejects a whole request with a reason, and a request whose every line is rejected', function () {
    ($this->send)([($this->asset)($this->notebook)]);
    $request = CheckoutRequest::sole();

    $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/reject")->assertSessionHasErrors('reject_reason');
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/reject", ['reject_reason' => 'No'])->assertForbidden();
    $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/reject", ['reject_reason' => 'Needed for the project'])->assertSessionHasNoErrors();
    expect($request->fresh()->only(['status', 'reject_reason']))->toBe(['status' => 'rejected', 'reject_reason' => 'Needed for the project'])
        ->and($request->items()->sole()->only(['status', 'reject_reason']))->toBe(['status' => 'rejected', 'reject_reason' => 'Needed for the project']);

    ($this->send)([($this->asset)($this->notebook)]);
    $second = CheckoutRequest::latest('id')->first();
    $line = $second->items()->sole();
    $this->actingAs($this->admin)->post("/checkout-requests/{$second->ulid}/approve", ['lines' => [$line->id => ['reject_reason' => 'Broken']]])->assertSessionHasNoErrors();
    expect($second->fresh()->status)->toBe('rejected')
        ->and($line->fresh()->status)->toBe('rejected');
});

it('hands out an asset to the borrower and takes it back with its condition', function () {
    $request = ($this->approved)([($this->asset)($this->notebook, ['checkout_type' => 'loan', 'due_return_date' => '2026-10-15'])]);
    $line = $request->items()->sole();

    $this->actingAs($this->staff)->post("/checkout-items/{$line->id}/fulfill", ['qty' => 1])->assertForbidden();
    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/fulfill", ['qty' => 2])->assertSessionHasErrors('qty');
    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/fulfill", ['qty' => 1])->assertSessionHasNoErrors();

    expect($line->fresh()->only(['status', 'qty_fulfilled']))->toBe(['status' => 'fulfilled', 'qty_fulfilled' => 1])
        ->and($line->fulfillments()->sole()->only(['qty', 'fulfilled_by_name', 'stock_movement_id']))->toBe(['qty' => 1, 'fulfilled_by_name' => 'Desk One', 'stock_movement_id' => null])
        ->and($request->fresh()->status)->toBe('fulfilled')
        ->and($this->notebook->fresh()->only(['status', 'used_by']))->toBe(['status' => Asset::STATUS_IN_USE, 'used_by' => 'Somchai Office']);

    // the device page and the same-model table say who has it
    $this->actingAs($this->admin)->get("/assets/{$this->notebook->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('checkouts.lines.0.status', 'fulfilled')
        ->where('checkouts.lines.0.outstanding', 1)
        ->where('checkouts.available', false)
        ->where('sameModel.0.holder', 'Somchai Office'));

    // fulfilled, but the loan is still out: the borrower (no asset-checkouts.return) cannot take it back
    $this->actingAs($this->staff)->post("/checkout-items/{$line->id}/return", ['qty' => 1])->assertForbidden();
    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/return", ['qty' => 1, 'condition' => 'Scratched lid'])->assertSessionHasNoErrors();

    expect($line->fresh()->only(['qty_returned', 'return_condition', 'returned_by_name']))->toBe(['qty_returned' => 1, 'return_condition' => 'Scratched lid', 'returned_by_name' => 'Desk One'])
        ->and($this->notebook->fresh()->only(['status', 'used_by']))->toBe(['status' => Asset::STATUS_SPARE, 'used_by' => null])
        ->and(app(AssetHeldQuantities::class)->handle([$this->notebook->id]))->toBe([]);

    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/return", ['qty' => 1])->assertSessionHasErrors('qty');

    // every line finished: it may be closed
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/close")->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('closed')
        ->and($request->fresh()->closed_at)->not->toBeNull();
});

it('hands out a lot in parts, then gives up the rest with a reason and closes', function () {
    $request = ($this->approved)([($this->asset)($this->cables, ['qty' => 4])]);
    $line = $request->items()->sole();

    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/fulfill", ['qty' => 1])->assertSessionHasNoErrors();
    expect($line->fresh()->only(['status', 'qty_fulfilled']))->toBe(['status' => 'partial', 'qty_fulfilled' => 1])
        ->and($request->fresh()->status)->toBe('partial')
        // a lot stays as it is; 4 are held (1 out, 3 still to hand out)
        ->and($this->cables->fresh()->status)->toBe(Asset::STATUS_SPARE)
        ->and(app(AssetHeldQuantities::class)->handle([$this->cables->id]))->toBe([$this->cables->id => 4]);

    // not finished: no closing yet
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/close")->assertSessionHasErrors('request');

    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/cancel")->assertSessionHasErrors('reason');
    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/cancel", ['reason' => 'Enough for now'])->assertSessionHasNoErrors();
    expect($line->fresh()->only(['status', 'qty_approved', 'qty_fulfilled', 'reject_reason']))->toBe([
        'status' => 'fulfilled', 'qty_approved' => 1, 'qty_fulfilled' => 1, 'reject_reason' => 'Enough for now',
    ])
        ->and($request->fresh()->status)->toBe('fulfilled')
        // an issue is not given back: one cable stays out
        ->and(app(AssetHeldQuantities::class)->handle([$this->cables->id]))->toBe([$this->cables->id => 1]);

    // the staff member (neither approve nor fulfill) does not close it
    $this->actingAs($this->staff)->post("/checkout-requests/{$request->ulid}/close")->assertForbidden();
    $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/close")->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('closed');

    // a line given up before anything went out is cancelled
    $other = ($this->approved)([($this->asset)($this->notebook)]);
    $this->actingAs($this->desk)->post('/checkout-items/'.$other->items()->sole()->id.'/cancel', ['reason' => 'Bought one'])->assertSessionHasNoErrors();
    expect($other->items()->sole()->status)->toBe('cancelled')
        ->and($other->fresh()->status)->toBe('fulfilled');
});

it('issues parts out of stock against the ticket, backorders what is short and orders it', function () {
    $ssd = createPart(['code' => 'SSD1', 'name' => 'SSD 1TB', 'unit' => 'ลูก'], stock: 2);
    $request = ($this->approved)([($this->part)(4), ['item_type' => 'part', 'part_id' => $ssd->id, 'qty' => 6]], ['ticket_id' => $this->ticket->id]);
    [$ramLine, $ssdLine] = $request->items->all();

    // some of the RAM: enough left in stock for the rest, so it is partly handed out
    $this->actingAs($this->desk)->post("/checkout-items/{$ramLine->id}/fulfill", ['qty' => 1])->assertSessionHasNoErrors();
    $movement = StockMovement::where('part_id', $this->ram->id)->where('type', StockMovement::TYPE_ISSUE)->sole();
    expect($ramLine->fresh()->status)->toBe('partial')
        ->and($movement->only(['quantity', 'ticket_id']))->toBe(['quantity' => -1, 'ticket_id' => $this->ticket->id])
        ->and($ramLine->fulfillments()->sole()->stock_movement_id)->toBe($movement->id)
        ->and($this->ram->fresh()->qty_on_hand)->toBe(4)
        ->and($request->fresh()->status)->toBe('partial');

    // never more than is on hand
    $this->actingAs($this->desk)->post("/checkout-items/{$ssdLine->id}/fulfill", ['qty' => 3])->assertSessionHasErrors();
    expect($ssd->fresh()->qty_on_hand)->toBe(2);

    // all the SSDs there are: the rest is backordered (owed, not lost)
    $this->actingAs($this->desk)->post("/checkout-items/{$ssdLine->id}/fulfill", ['qty' => 2])->assertSessionHasNoErrors();
    expect($ssdLine->fresh()->only(['status', 'qty_fulfilled']))->toBe(['status' => 'backordered', 'qty_fulfilled' => 2])
        ->and($ssdLine->fresh()->remaining())->toBe(4);

    // order the missing ones: a purchase request tied to the line
    $this->actingAs($this->desk)->post("/checkout-items/{$ssdLine->id}/backorder", ['order' => true])->assertSessionHasNoErrors();
    $purchase = PurchaseRequest::sole();
    expect($ssdLine->fresh()->purchase_request_id)->toBe($purchase->id)
        ->and($purchase->only(['item_name', 'quantity', 'unit']))->toBe(['item_name' => 'SSD 1TB', 'quantity' => 4, 'unit' => 'ลูก'])
        ->and($purchase->reason)->toContain('CR-2569-00001');
    $this->actingAs($this->desk)->post("/checkout-items/{$ssdLine->id}/backorder", ['order' => true])->assertSessionHasErrors('item');

    // the request page shows the purchase request and what is on hand
    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('Asset/Requests/Show')
        ->where('request.items.1.purchase_request.pr_no', $purchase->pr_no)
        ->where('request.items.1.on_hand', 0)
        ->where('request.items.0.fulfillments.0.qty', 1)
        ->where('can.fulfill', true)
        ->where('can.approve', false));

    // the part page lists the line
    $this->actingAs($this->desk)->get("/parts/{$ssd->id}")->assertInertia(fn (Assert $page) => $page
        ->where('checkouts.lines.0.status', 'backordered')
        ->where('checkouts.lines.0.request.request_no', 'CR-2569-00001')
        ->where('checkouts.available', true)
        ->where('checkouts.available_quantity', 0)
        ->where('checkouts.unit', 'ลูก')
        ->where('checkouts.can.create', true));

    // the stock comes in: the line is ready to hand out again
    app(RecordStockMovement::class)->handle($ssd->fresh(), StockMovement::TYPE_RECEIVE, 5, $this->admin);
    expect($ssdLine->fresh()->status)->toBe('partial');
    $this->actingAs($this->desk)->post("/checkout-items/{$ssdLine->id}/fulfill", ['qty' => 4])->assertSessionHasNoErrors();
    expect($ssdLine->fresh()->status)->toBe('fulfilled');
});

it('backorders by hand, and readies a line with nothing handed out yet when stock comes in', function () {
    $empty = createPart(['code' => 'FAN', 'name' => 'Fan', 'unit' => 'ตัว']);
    $request = ($this->approved)([['item_type' => 'part', 'part_id' => $empty->id, 'qty' => 2]], ['ticket_id' => $this->ticket->id]);
    $line = $request->items()->sole();

    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/backorder")->assertSessionHasNoErrors();
    expect($line->fresh()->only(['status', 'purchase_request_id']))->toBe(['status' => 'backordered', 'purchase_request_id' => null]);

    // a technician cannot order (no asset-checkouts.fulfill)
    $this->actingAs(userWithRole('technician'))->post("/checkout-items/{$line->id}/backorder", ['order' => true])->assertForbidden();

    app(RecordStockMovement::class)->handle($empty->fresh(), StockMovement::TYPE_RECEIVE, 1, $this->admin);
    expect($line->fresh()->status)->toBe('approved');
});

it('cancels a request: by the requester before the decision, never after', function () {
    $tech = userWithRole('technician', ['name' => 'Tech One']);
    ($this->send)([($this->asset)($this->notebook)], [], $tech)->assertSessionHasNoErrors();
    $request = CheckoutRequest::sole();

    // someone else who is not an approver may not
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/cancel")->assertForbidden();
    $this->actingAs($tech)->post("/checkout-requests/{$request->ulid}/cancel")->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('cancelled')
        ->and($request->items()->sole()->status)->toBe('cancelled')
        ->and(app(AssetHeldQuantities::class)->handle([$this->notebook->id]))->toBe([]);

    $approved = ($this->approved)([($this->asset)($this->notebook)]);
    $this->actingAs($this->desk)->post("/checkout-requests/{$approved->ulid}/cancel")->assertForbidden();
    $this->actingAs($this->admin)->post("/checkout-requests/{$approved->ulid}/cancel")->assertForbidden();
    expect($approved->fresh()->status)->toBe('approved');
});

it('approves a request of cheap parts at once when the company allows it', function () {
    $tenant = $this->tenant->fresh();
    $tenant->update(['settings' => [...($tenant->settings ?? []), 'checkout' => ['auto_approve_limit' => 100000]]]); // 1,000 baht a line

    // 2 × 500 baht: within the limit
    ($this->send)([($this->part)(2)], ['ticket_id' => $this->ticket->id])->assertSessionHasNoErrors();
    $request = CheckoutRequest::latest('id')->first();
    expect($request->only(['status', 'auto_approved']))->toBe(['status' => 'approved', 'auto_approved' => true])
        ->and($request->items()->sole()->only(['status', 'qty_approved']))->toBe(['status' => 'approved', 'qty_approved' => 2]);

    // over the limit, or with an asset: an approver decides
    ($this->send)([($this->part)(3)], ['ticket_id' => $this->ticket->id]);
    expect(CheckoutRequest::latest('id')->first()->status)->toBe('pending');
    ($this->send)([($this->part)(1), ($this->asset)($this->cables)], ['ticket_id' => $this->ticket->id]);
    expect(CheckoutRequest::latest('id')->first()->status)->toBe('pending');

    // the limit is off by default
    $tenant->update(['settings' => [...$tenant->fresh()->settings, 'checkout' => ['auto_approve_limit' => null]]]);
    ($this->send)([($this->part)(1)], ['ticket_id' => $this->ticket->id]);
    expect(CheckoutRequest::latest('id')->first()->status)->toBe('pending');
});

it('prints the hand-over form once approved, as a page or a PDF', function () {
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf'])]);
    ($this->send)([($this->asset)($this->notebook, ['checkout_type' => 'loan', 'due_return_date' => '2026-10-15']), ($this->asset)($this->cables, ['qty' => 2])]);
    $request = CheckoutRequest::sole();

    // not before approval
    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}/print")->assertNotFound();

    $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve");

    // by whoever handles it, and by the borrower (their own request)
    $this->actingAs($this->staff)->get("/checkout-requests/{$request->ulid}/print")->assertOk();
    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}/print")->assertOk()
        ->assertSee('CR-2569-00001')
        ->assertSee('Somchai Office')
        ->assertSee('Notebook Lenovo')
        ->assertSee('LAN cable')
        ->assertSee('window.print', false);
    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

    // a technician who neither asked nor receives it does not reach it
    $this->actingAs(userWithRole('technician'))->get("/checkout-requests/{$request->ulid}/print")->assertForbidden();
});

it('records an asset registered as already handed out as a request handed out', function () {
    $this->actingAs($this->admin)->post('/assets', [
        'category_id' => $this->notebook->category_id, 'name' => 'Notebook HP', 'status' => 'loaned', 'owner' => 'company', 'location' => 'Store',
        'holder_name' => 'Somsri', 'handed_out_on' => '2026-09-15',
    ])->assertSessionHasNoErrors();

    $asset = Asset::where('name', 'Notebook HP')->sole();
    $request = CheckoutRequest::sole();
    $line = $request->items()->sole();
    expect($asset->status)->toBe(Asset::STATUS_IN_USE)
        ->and($request->only(['status', 'borrower_name', 'requester_name', 'approved_by_name']))->toBe([
            'status' => 'fulfilled', 'borrower_name' => 'Somsri', 'requester_name' => 'Admin Boss', 'approved_by_name' => 'Admin Boss',
        ])
        ->and($request->approved_at->toDateString())->toBe('2026-09-15')
        ->and($line->only(['asset_id', 'checkout_type', 'status', 'qty_fulfilled']))->toBe(['asset_id' => $asset->id, 'checkout_type' => 'loan', 'status' => 'fulfilled', 'qty_fulfilled' => 1])
        ->and($line->fulfillments()->sole()->fulfilled_at->toDateString())->toBe('2026-09-15');

    // it comes back like any loan
    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/return", ['qty' => 1])->assertSessionHasNoErrors();
    expect($asset->fresh()->status)->toBe(Asset::STATUS_SPARE);
});
