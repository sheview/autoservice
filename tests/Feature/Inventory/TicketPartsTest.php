<?php

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\MoveTicket;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/*
 * Parts on a ticket: asked for on an issue request for the job (approved, then handed out, which
 * takes them out of stock), listed on the ticket, and given back to stock from it.
 */

beforeEach(function () {
    $this->helpdesk = userWithRole('helpdesk');
    $this->admin = userWithRole('admin_company');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer();
    $this->ticket = openTicket($this->helpdesk, ['customer_id' => $this->customer->id]);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);

    $this->ram = createPart(['code' => 'RAM', 'name' => 'Memory'], stock: 5);
    $this->url = "/tickets/{$this->ticket->ulid}";
});

it('takes parts out of stock only once the request for the job is approved and handed out', function () {
    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->component('Service/Tickets/Show')
        ->where('parts.items', [])
        ->where('parts.requests', [])
        ->where('parts.requestUrl', route('asset.requests.create', ['ticket' => $this->ticket->id])));

    // no taking parts straight from the ticket any more
    expect($this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 1])->status())->toBeIn([404, 405]);

    $this->actingAs($this->tech)->post('/checkout-requests', [
        'ticket_id' => $this->ticket->id, 'submit' => true,
        'items' => [['purpose' => 'delivery', 'for_internal' => true, 'item_type' => 'part', 'part_id' => $this->ram->id, 'qty' => 2]],
    ])->assertSessionHasNoErrors();
    $request = CheckoutRequest::latest('id')->first();

    // waiting for approval: on the ticket, nothing out of stock
    expect($this->ram->fresh()->qty_on_hand)->toBe(5);
    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('parts.items', [])
        ->where('parts.requests.0.request_no', $request->request_no)
        ->where('parts.requests.0.status', CheckoutRequest::STATUS_PENDING));

    $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve")->assertSessionHasNoErrors();
    expect($this->ram->fresh()->qty_on_hand)->toBe(5);

    $this->actingAs($this->helpdesk)->post("/checkout-items/{$request->items()->first()->id}/fulfill", ['qty' => 2])->assertSessionHasNoErrors();

    expect($this->ram->fresh()->qty_on_hand)->toBe(3)
        ->and(StockMovement::where('ticket_id', $this->ticket->id)->get(['type', 'quantity'])->toArray())->toBe([['type' => 'issue', 'quantity' => -2]]);
    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('parts.items', [['part_id' => $this->ram->id, 'code' => 'RAM', 'name' => 'Memory', 'unit' => 'pcs', 'quantity' => 2, 'types' => ['issue'], 'track_serial' => false, 'units' => []]])
        ->where('parts.requests.0.status', fn ($status) => in_array($status, [CheckoutRequest::STATUS_FULFILLED, CheckoutRequest::STATUS_CLOSED], true)));
});

it('returns parts to stock, at most what the ticket still holds', function () {
    issueToTicket($this->ticket, $this->ram, 3, $this->tech);

    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 4])->assertSessionHasErrors('quantity');
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 1])->assertSessionHasNoErrors();

    expect($this->ram->fresh()->qty_on_hand)->toBe(3)
        ->and(StockMovement::latest('id')->first()->only(['type', 'quantity', 'balance_after', 'ticket_id']))
        ->toBe(['type' => 'return', 'quantity' => 1, 'balance_after' => 3, 'ticket_id' => $this->ticket->id]);

    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 2])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 1])->assertSessionHasErrors('quantity');

    // everything came back: the ticket lists no parts
    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts.items', []));

    // a part issued to another ticket cannot be returned from this one
    $elsewhere = openTicket($this->helpdesk);
    issueToTicket($elsewhere, $this->ram, 1, $this->tech);
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 1])->assertSessionHasErrors('quantity');
});

it('only lets staff who work on the ticket give parts back, and shows customers nothing about stock', function () {
    issueToTicket($this->ticket, $this->ram, 1, $this->tech);
    $payload = ['part_id' => $this->ram->id, 'quantity' => 1];

    // a technician who is not on the ticket (parts.issue scope own) may not
    $this->actingAs(userWithRole('technician'))->post("{$this->url}/parts/return", $payload)->assertForbidden();

    // a customer account sees its ticket but nothing about stock
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts', null));
    $this->actingAs($client)->post("{$this->url}/parts/return", $payload)->assertForbidden();

    expect($this->ram->fresh()->qty_on_hand)->toBe(4);
});

it('stops asking for parts once the ticket is closed or cancelled', function () {
    app(MoveTicket::class)->handle($this->ticket, 'cancel', $this->helpdesk, 'duplicate');

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts.requestUrl', null));
});

it('takes back lent parts and spares after the job is closed', function () {
    issueToTicket($this->ticket, $this->ram, 1, $this->tech, StockMovement::TYPE_LOAN);
    issueToTicket($this->ticket, $this->ram, 2, $this->tech, StockMovement::TYPE_SPARE);

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('parts.items.0.quantity', 3)
        ->where('parts.items.0.types', ['loan', 'spare']));

    // the job is closed; nothing more can be asked for, but what was lent still comes back
    app(MoveTicket::class)->handle($this->ticket, 'start', $this->tech);
    app(MoveTicket::class)->handle($this->ticket, 'resolve', $this->tech);
    app(MoveTicket::class)->handle($this->ticket, 'approve', $this->admin);

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('parts.requestUrl', null)->where('parts.canReturn', true));
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 3])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 1])->assertSessionHasErrors('quantity');

    expect($this->ram->fresh()->qty_on_hand)->toBe(5);
});

it('drops the parts section when the inventory module is switched off', function () {
    Feature::for($this->tenant)->deactivate(Modules::feature('inventory'));

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts', null));
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 1])->assertNotFound();
});
