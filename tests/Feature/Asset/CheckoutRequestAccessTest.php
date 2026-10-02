<?php

use App\Modules\Asset\Actions\NotifyCheckoutDelays;
use App\Modules\Asset\Jobs\NotifyCheckoutDelaysJob;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Jobs\DeliverAlert;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Issue/loan requests: who may do what, the list tabs, and the alerts (CheckoutRequestTest has
 * the request itself).
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->staff = userWithRole('user', ['name' => 'Somchai Office']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $category = createAssetCategory(['name' => 'Notebook']);
    $this->notebook = createAsset($category, ['name' => 'Notebook Lenovo', 'status' => Asset::STATUS_SPARE]);
    $this->projector = createAsset($category, ['name' => 'Projector Epson', 'status' => Asset::STATUS_SPARE]);
    $this->cables = createAsset($category, ['name' => 'LAN cable', 'quantity' => 50, 'unit' => 'เส้น', 'status' => Asset::STATUS_SPARE]);
    $this->fan = createPart(['code' => 'FAN', 'name' => 'Fan', 'unit' => 'ตัว']);
    $this->ticket = openTicket($this->desk);

    $this->line = fn (Asset $asset, array $line = []) => $line + ['item_type' => 'asset', 'asset_id' => $asset->id, 'qty' => 1, 'checkout_type' => 'issue'];
    $this->send = function (array $items, array $data = [], $user = null): CheckoutRequest {
        $this->actingAs($user ?? $this->desk)->post('/checkout-requests', $data + [
            'borrower_user_id' => $this->staff->id, 'submit' => true, 'items' => $items,
        ])->assertSessionHasNoErrors();

        return CheckoutRequest::latest('id')->first();
    };
    $this->approve = function (CheckoutRequest $request): CheckoutRequest {
        $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve")->assertSessionHasNoErrors();

        return $request->fresh();
    };
    $this->alertsOn = fn (array $events) => app(SaveAlertSettings::class)->handle($this->tenant, [
        'events' => $events,
        'line' => ['enabled' => true, 'to' => 'Cgroup123', 'token' => 'line-secret-token'],
        'telegram' => ['enabled' => false],
        'mail' => ['enabled' => false],
    ]);
});

it('makes whoever may only ask for themself the receiver, whatever the form says', function () {
    $this->actingAs($this->staff)->get('/checkout-requests/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Asset/Requests/Form')
        ->where('borrowers', [])
        ->where('can.forOthers', false));

    $request = ($this->send)([($this->line)($this->notebook)], ['borrower_user_id' => $this->desk->id, 'borrower_name' => 'Not me'], $this->staff);
    expect($request->only(['borrower_user_id', 'borrower_name', 'requester_id']))->toBe([
        'borrower_user_id' => $this->staff->id, 'borrower_name' => 'Somchai Office', 'requester_id' => $this->staff->id,
    ]);

    // helpdesk chooses among the staff, or writes the name of someone from outside
    $this->actingAs($this->desk)->get('/checkout-requests/create?asset='.$this->projector->ulid)->assertInertia(fn (Assert $page) => $page
        ->where('can.forOthers', true)
        ->where('firstItem.asset_id', $this->projector->id)
        ->where('borrowers', fn ($users) => collect($users)->pluck('name')->contains('Somchai Office')));
    $outside = ($this->send)([($this->line)($this->projector)], ['borrower_user_id' => null, 'borrower_name' => 'Contractor Lek']);
    expect($outside->only(['borrower_user_id', 'borrower_name']))->toBe(['borrower_user_id' => null, 'borrower_name' => 'Contractor Lek']);

    // a user of another company is no one to choose
    $outsider = userWithRole('admin_company', [], createTenant('other'));
    $this->actingAs($this->desk)->post('/checkout-requests', [
        'borrower_user_id' => $outsider->id, 'items' => [($this->line)($this->cables)],
    ])->assertSessionHasErrors('borrower_user_id');
});

it('separates approving from handing out', function () {
    $request = ($this->send)([($this->line)($this->notebook)]);

    // helpdesk: hands out and takes back, does not approve
    $this->actingAs($this->desk)->get('/checkout-requests')->assertInertia(fn (Assert $page) => $page
        ->where('can.approve', false)->where('can.fulfill', true)->where('can.return', true)
        ->where('counts.approve', null));
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/approve")->assertForbidden();
    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page->where('can.approve', false));

    // the admin approves
    $this->actingAs($this->admin)->get("/checkout-requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page->where('can.approve', true));
    ($this->approve)($request);

    // an approver without fulfill does not hand out
    grantTo('technician', ['asset-checkouts.approve']);
    $line = $request->items()->sole();
    $this->actingAs($this->tech)->post("/checkout-items/{$line->id}/fulfill", ['qty' => 1])->assertForbidden();
    $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/fulfill", ['qty' => 1])->assertSessionHasNoErrors();
});

it('shows a technician only their own requests', function () {
    $other = userWithRole('technician', ['name' => 'Tech Two']);
    $mine = ($this->send)([($this->line)($this->notebook)], [], $this->tech);
    $theirs = ($this->send)([($this->line)($this->projector)], [], $other);
    ($this->send)([($this->line)($this->cables)]);

    $this->actingAs($this->tech)->get('/checkout-requests')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Asset/Requests/Index')
        ->where('requests.total', 1)
        ->where('requests.data.0.request_no', $mine->request_no));
    $this->actingAs($this->tech)->get("/checkout-requests/{$mine->ulid}")->assertOk();
    $this->actingAs($this->tech)->get("/checkout-requests/{$theirs->ulid}")->assertForbidden();
    $this->actingAs($this->tech)->post("/checkout-requests/{$theirs->ulid}/cancel")->assertForbidden();

    // the asset page lists only the lines of requests the user reaches
    $this->actingAs($this->admin)->get("/assets/{$this->projector->ulid}")->assertInertia(fn (Assert $page) => $page->has('checkouts.lines', 1));
    $this->actingAs($this->tech)->get("/assets/{$this->projector->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('checkouts.lines', 0)
        // held by the other request: nothing left, though the technician may ask for it
        ->where('checkouts.available', false)
        ->where('checkouts.can.create', true));

    // the whole company sees all of them
    $this->actingAs($this->admin)->get('/checkout-requests')->assertInertia(fn (Assert $page) => $page->where('requests.total', 3));
});

it('keeps each company to its own requests', function () {
    $request = ($this->approve)(($this->send)([($this->line)($this->notebook)]));
    $line = $request->items()->sole();

    $other = createTenant('other');
    $otherAdmin = userWithRole('admin_company', [], $other);
    $this->actingAs($otherAdmin)->get("/checkout-requests/{$request->ulid}")->assertNotFound();
    $this->actingAs($otherAdmin)->post("/checkout-requests/{$request->ulid}/close")->assertNotFound();
    $this->actingAs($otherAdmin)->get("/checkout-requests/{$request->ulid}/print")->assertNotFound();
    $this->actingAs($otherAdmin)->post("/checkout-items/{$line->id}/fulfill", ['qty' => 1])->assertNotFound();
    $this->actingAs($otherAdmin)->post("/checkout-items/{$line->id}/cancel", ['reason' => 'x'])->assertNotFound();
    $this->actingAs($otherAdmin)->get('/checkout-requests?status=all')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
    asTenant($other, fn () => expect(CheckoutRequest::count())->toBe(0)->and(CheckoutItem::count())->toBe(0));

    expect($line->fresh()->qty_fulfilled)->toBe(0);

    // nor do customer accounts reach requests at all
    $client = userWithRole('customer_it', ['customer_id' => createCustomer()->id]);
    $this->actingAs($client)->get('/checkout-requests')->assertForbidden();
    $this->actingAs($client)->get('/checkout-requests/create')->assertForbidden();
});

it('lists by tab, with search, filters, sort and pages', function () {
    $waiting = ($this->send)([($this->line)($this->notebook)], ['needed_by' => '2026-10-20', 'purpose' => 'Training room']);
    $toHand = ($this->approve)(($this->send)([($this->line)($this->projector)], ['needed_by' => '2026-10-05']));
    $owed = ($this->approve)(($this->send)([['item_type' => 'part', 'part_id' => $this->fan->id, 'qty' => 2]], ['ticket_id' => $this->ticket->id]));
    $this->actingAs($this->desk)->post('/checkout-items/'.$owed->items()->sole()->id.'/backorder')->assertSessionHasNoErrors();
    $lent = ($this->approve)(($this->send)([($this->line)($this->cables, ['qty' => 3, 'checkout_type' => 'loan', 'due_return_date' => '2026-10-03'])], ['borrower_user_id' => null, 'borrower_name' => 'Contractor Lek']));
    $this->actingAs($this->desk)->post('/checkout-items/'.$lent->items()->sole()->id.'/fulfill', ['qty' => 3])->assertSessionHasNoErrors();
    $this->travelTo('2026-10-04 10:00');

    $page = fn (string $query) => $this->actingAs($this->admin)->get("/checkout-requests{$query}");
    $page('')->assertInertia(fn (Assert $p) => $p
        ->where('filters.tab', 'requests')
        ->where('requests.total', 4)
        ->where('requests.data.0.request_no', $lent->request_no) // newest first
        ->where('lines', null)
        ->where('counts', ['approve' => 1, 'fulfill' => 1, 'backorders' => 1, 'returns' => 1]));
    $page('?tab=approve')->assertInertia(fn (Assert $p) => $p->where('requests.total', 1)->where('requests.data.0.request_no', $waiting->request_no));
    $page('?tab=fulfill')->assertInertia(fn (Assert $p) => $p->where('requests.total', 1)->where('requests.data.0.request_no', $toHand->request_no));
    $page('?tab=backorders')->assertInertia(fn (Assert $p) => $p
        ->where('requests', null)
        ->where('lines.total', 1)
        ->where('lines.data.0.item_name', 'Fan')
        ->where('lines.data.0.request.request_no', $owed->request_no));
    $page('?tab=returns&overdue=1')->assertInertia(fn (Assert $p) => $p
        ->where('lines.total', 1)
        ->where('lines.data.0.overdue', true)
        ->where('lines.data.0.outstanding', 3));

    // search: number, people, purpose, items
    $page('?search=Lek')->assertInertia(fn (Assert $p) => $p->where('requests.total', 1));
    $page('?search=training')->assertInertia(fn (Assert $p) => $p->where('requests.data.0.request_no', $waiting->request_no));
    $page('?search=projector')->assertInertia(fn (Assert $p) => $p->where('requests.total', 1)->where('requests.data.0.request_no', $toHand->request_no));
    $page("?search={$owed->request_no}")->assertInertia(fn (Assert $p) => $p->where('requests.total', 1));
    // filter and sort
    $page('?status=pending')->assertInertia(fn (Assert $p) => $p->where('requests.total', 1));
    $page('?sort=needed_by&direction=asc')->assertInertia(fn (Assert $p) => $p
        ->where('requests.data.0.request_no', $toHand->request_no)
        ->where('requests.data.1.request_no', $waiting->request_no));

    // the borrower sees theirs; closed ones under "all"
    $this->actingAs($this->staff)->get('/checkout-requests')->assertInertia(fn (Assert $p) => $p->where('requests.total', 3));

    // 20 a page
    foreach (range(1, 18) as $n) {
        ($this->send)([($this->line)($this->cables)]);
    }
    $page('?status=all')->assertInertia(fn (Assert $p) => $p->where('requests.total', 22)->has('requests.data', 20)->where('requests.last_page', 2));
    $page('?status=all&page=2')->assertInertia(fn (Assert $p) => $p->has('requests.data', 2));
});

it('alerts when a request is sent, and when a backordered part comes into stock', function () {
    Queue::fake();
    ($this->alertsOn)(['checkout_requested', 'checkout_approved', 'checkout_restocked']);

    $request = ($this->send)([['item_type' => 'part', 'part_id' => $this->fan->id, 'qty' => 2]], ['ticket_id' => $this->ticket->id]);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'checkout_requested'
        && str_contains($job->title, $request->request_no) && str_contains($job->body, 'Fan × 2'));

    ($this->approve)($request);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'checkout_approved');
    $this->actingAs($this->desk)->post('/checkout-items/'.$request->items()->sole()->id.'/backorder')->assertSessionHasNoErrors();

    app(RecordStockMovement::class)->handle($this->fan->fresh(), StockMovement::TYPE_RECEIVE, 5, $this->admin);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'checkout_restocked' && str_contains($job->title, $request->request_no));
    expect($request->items()->sole()->status)->toBe('approved');
});

it('alerts once each about late approvals, late backorders and loans past due', function () {
    Queue::fake();
    ($this->alertsOn)(['checkout_approval_overdue', 'checkout_backorder_overdue', 'checkout_return_overdue']);

    $lent = ($this->approve)(($this->send)([($this->line)($this->notebook, ['checkout_type' => 'loan', 'due_return_date' => '2026-10-03'])]));
    $this->actingAs($this->desk)->post('/checkout-items/'.$lent->items()->sole()->id.'/fulfill', ['qty' => 1]);
    $owed = ($this->approve)(($this->send)([['item_type' => 'part', 'part_id' => $this->fan->id, 'qty' => 2]], ['ticket_id' => $this->ticket->id]));
    $this->actingAs($this->desk)->post('/checkout-items/'.$owed->items()->sole()->id.'/backorder');
    $waiting = ($this->send)([($this->line)($this->projector)]);

    // nothing late yet
    expect(app(NotifyCheckoutDelays::class)->handle())->toBe(0);

    // 2 days on: waiting more than 24 hours (the loan is due today, the backorder not 3 days old)
    $this->travelTo('2026-10-03 11:00');
    expect(app(NotifyCheckoutDelays::class)->handle())->toBe(1);
    // a day later: the loan is past due, the backorder 3 days old
    $this->travelTo('2026-10-04 11:00');
    expect(app(NotifyCheckoutDelays::class)->handle())->toBe(2);

    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'checkout_approval_overdue' && str_contains($job->title, $waiting->request_no));
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'checkout_return_overdue' && str_contains($job->title, $lent->request_no));
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'checkout_backorder_overdue' && str_contains($job->title, $owed->request_no));

    // sent once each
    expect(app(NotifyCheckoutDelays::class)->handle())->toBe(0)
        ->and($waiting->fresh()->approval_alerted_at)->not->toBeNull()
        ->and($owed->items()->sole()->backorder_alerted_at)->not->toBeNull()
        ->and($lent->items()->sole()->overdue_alerted_at)->not->toBeNull();

    // the hourly command queues the check for each company
    $this->artisan('checkouts:notify-delays')->assertSuccessful();
    Queue::assertPushed(NotifyCheckoutDelaysJob::class);
});
