<?php

use App\Modules\Asset\Actions\NotifyCheckoutDelays;
use App\Modules\Asset\Models\CheckoutFulfillment;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Jobs\DeliverAlert;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The receiver confirms what was handed out to them, or reports it short / never came (the stock
 * is told at once); hand-outs left unconfirmed are alerted about and listed.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    // helpdesk: writes requests for anyone and hands them out
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->staff = userWithRole('user', ['name' => 'Somchai Office']);
    $this->ram = createPart(['code' => 'RAM8', 'name' => 'RAM 8GB', 'unit' => 'แผง'], stock: 5);
    $this->ticket = openTicket($this->desk);

    // Approved and handed out (qty of RAM) for the staff member, or for $data's borrower.
    $this->handedOut = function (int $qty = 2, array $data = []): CheckoutRequest {
        $this->actingAs($this->desk)->post('/checkout-requests', $data + [
            'borrower_user_id' => $this->staff->id, 'ticket_id' => $this->ticket->id, 'submit' => true,
            'items' => [['item_type' => 'part', 'part_id' => $this->ram->id, 'qty' => $qty]],
        ])->assertSessionHasNoErrors();
        $request = CheckoutRequest::latest('id')->first();
        $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->desk)->post('/checkout-items/'.$request->items()->sole()->id.'/fulfill', ['qty' => $qty])->assertSessionHasNoErrors();

        return $request->fresh();
    };
    $this->alertsOn = fn (array $events) => app(SaveAlertSettings::class)->handle($this->tenant, [
        'events' => $events,
        'line' => ['enabled' => true, 'to' => 'Cgroup123', 'token' => 'line-secret-token'],
        'telegram' => ['enabled' => false],
        'mail' => ['enabled' => false],
    ]);
});

it('lets the receiver confirm what was handed out, and no one else', function () {
    $request = ($this->handedOut)();
    $fulfillment = CheckoutFulfillment::sole();
    expect($fulfillment->awaitsReceipt())->toBeTrue()
        // handed out in full: closed as before, still waiting for the receiver
        ->and($request->status)->toBe('closed');

    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('can.confirm_receipt', false)
        ->where('request.items.0.fulfillments.0.received_at', null));
    // who handed it out cannot say it came
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/confirm-receipt")->assertForbidden();

    $this->actingAs($this->staff)->get("/checkout-requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page->where('can.confirm_receipt', true));
    $this->actingAs($this->staff)->post("/checkout-requests/{$request->ulid}/confirm-receipt")->assertSessionHasNoErrors();

    expect($fulfillment->fresh()->only(['received_by', 'received_by_name', 'receipt_issue']))
        ->toBe(['received_by' => $this->staff->id, 'received_by_name' => 'Somchai Office', 'receipt_issue' => null])
        ->and($fulfillment->fresh()->received_at->toDateTimeString())->toBe('2026-10-01 10:00:00');
    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('request.items.0.fulfillments.0.received_by', 'Somchai Office'));

    // nothing left to confirm
    $this->actingAs($this->staff)->post("/checkout-requests/{$request->ulid}/confirm-receipt")->assertSessionHasErrors('request');
});

it('makes the requester the receiver when the borrower has no account', function () {
    $request = ($this->handedOut)(1, ['borrower_user_id' => null, 'borrower_name' => 'Outside Tech']);

    $this->actingAs($this->staff)->post("/checkout-requests/{$request->ulid}/confirm-receipt")->assertForbidden();
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/confirm-receipt")->assertSessionHasNoErrors();
    expect(CheckoutFulfillment::sole()->received_by_name)->toBe('Desk One');
});

it('tells the stock at once when the receiver reports it short, and takes the confirmation once sorted out', function () {
    Queue::fake();
    ($this->alertsOn)(['checkout_receipt_issue']);
    $request = ($this->handedOut)(3);

    $this->actingAs($this->staff)->post("/checkout-requests/{$request->ulid}/confirm-receipt", ['report' => true, 'receipt_issue' => '  '])->assertSessionHasErrors('receipt_issue');
    $this->actingAs($this->staff)->post("/checkout-requests/{$request->ulid}/confirm-receipt", ['report' => true, 'receipt_issue' => 'ได้แค่ 2 แผง'])->assertSessionHasNoErrors();

    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'checkout_receipt_issue'
        && str_contains($job->title, $request->request_no) && str_contains($job->body, 'ได้แค่ 2 แผง') && str_contains($job->body, 'Somchai Office'));
    $fulfillment = CheckoutFulfillment::sole();
    expect($fulfillment->only(['received_at', 'receipt_issue']))->toBe(['received_at' => null, 'receipt_issue' => 'ได้แค่ 2 แผง'])
        ->and($fulfillment->awaitsReceipt())->toBeFalse();

    // reported once
    $this->actingAs($this->staff)->post("/checkout-requests/{$request->ulid}/confirm-receipt", ['report' => true, 'receipt_issue' => 'again'])->assertSessionHasErrors('request');

    // sorted out: confirmed, the report kept
    $this->actingAs($this->staff)->post("/checkout-requests/{$request->ulid}/confirm-receipt")->assertSessionHasNoErrors();
    expect($fulfillment->fresh()->received_at)->not->toBeNull()
        ->and($fulfillment->fresh()->receipt_issue)->toBe('ได้แค่ 2 แผง');
});

it('alerts once about hand-outs not confirmed in time, never about confirmed or reported ones', function () {
    Queue::fake();
    ($this->alertsOn)(['checkout_receipt_overdue']);
    $waiting = ($this->handedOut)(1);
    $confirmed = ($this->handedOut)(1);
    $reported = ($this->handedOut)(1);
    $this->actingAs($this->staff)->post("/checkout-requests/{$confirmed->ulid}/confirm-receipt")->assertSessionHasNoErrors();
    $this->actingAs($this->staff)->post("/checkout-requests/{$reported->ulid}/confirm-receipt", ['report' => true, 'receipt_issue' => 'ไม่ได้รับ'])->assertSessionHasNoErrors();

    $this->travelTo('2026-10-02 09:00');
    expect(app(NotifyCheckoutDelays::class)->handle())->toBe(0);

    $this->travelTo('2026-10-02 10:30');
    expect(app(NotifyCheckoutDelays::class)->handle())->toBe(1);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'checkout_receipt_overdue'
        && str_contains($job->title, $waiting->request_no) && str_contains($job->body, 'RAM 8GB × 1'));
    expect(app(NotifyCheckoutDelays::class)->handle())->toBe(0);
});

it('lists the requests waiting for the receiver or reported, closed ones too', function () {
    $waiting = ($this->handedOut)(1);
    $reported = ($this->handedOut)(1);
    ($this->handedOut)(1);
    $this->actingAs($this->staff)->post("/checkout-requests/{$reported->ulid}/confirm-receipt", ['report' => true, 'receipt_issue' => 'ไม่ได้รับ']);
    $this->actingAs($this->staff)->post('/checkout-requests/'.CheckoutRequest::latest('id')->first()->ulid.'/confirm-receipt');

    $page = fn (string $query) => $this->actingAs($this->desk)->get("/checkout-requests{$query}");
    $page('')->assertInertia(fn (Assert $p) => $p->where('counts.receipt_pending', 1)->where('counts.receipt_issue', 1));
    $page('?receipt=pending')->assertInertia(fn (Assert $p) => $p->where('requests.total', 1)->where('requests.data.0.request_no', $waiting->request_no));
    $page('?receipt=issue')->assertInertia(fn (Assert $p) => $p->where('requests.total', 1)->where('requests.data.0.request_no', $reported->request_no));
    $page('?receipt=nonsense')->assertInertia(fn (Assert $p) => $p->where('filters.receipt', 'all'));
});

it('keeps each company to its own hand-outs', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, function () {
        $user = userWithRole('admin_company');
        $part = createPart(['code' => 'X1', 'name' => 'Theirs'], stock: 2);
        $this->actingAs($user)->post('/checkout-requests', [
            'borrower_user_id' => $user->id, 'submit' => true, 'ticket_id' => openTicket($user)->id,
            'items' => [['item_type' => 'part', 'part_id' => $part->id, 'qty' => 1]],
        ])->assertSessionHasNoErrors();

        return CheckoutRequest::latest('id')->first();
    });

    $this->actingAs($this->staff)->post("/checkout-requests/{$theirs->ulid}/confirm-receipt")->assertNotFound();
    $this->actingAs($this->desk)->get('/checkout-requests?receipt=pending')->assertInertia(fn (Assert $p) => $p->where('requests.total', 0));
});
