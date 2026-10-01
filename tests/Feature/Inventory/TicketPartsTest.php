<?php

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\MoveTicket;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer();
    $this->ticket = openTicket($this->helpdesk, ['customer_id' => $this->customer->id]);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);

    $this->ram = createPart(['code' => 'RAM', 'name' => 'Memory'], stock: 5);
    $this->url = "/tickets/{$this->ticket->ulid}";
});

it('lets the technician take parts for a ticket and shows them on the ticket', function () {
    $this->actingAs($this->tech)->get($this->url)
        ->assertInertia(fn (Assert $page) => $page->component('Service/Tickets/Show')
            ->where('parts.items', [])
            ->where('parts.canIssue', true)
            ->where('parts.options.0', ['id' => $this->ram->id, 'code' => 'RAM', 'name' => 'Memory', 'unit' => 'pcs', 'qty_on_hand' => 5]));

    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 2])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 1])->assertSessionHasNoErrors();

    expect($this->ram->fresh()->qty_on_hand)->toBe(2)
        ->and(StockMovement::where('ticket_id', $this->ticket->id)->get(['type', 'quantity', 'user_name'])->toArray())->toBe([
            ['type' => 'issue', 'quantity' => -2, 'user_name' => 'Tech One'],
            ['type' => 'issue', 'quantity' => -1, 'user_name' => 'Tech One'],
        ]);

    $this->actingAs($this->tech)->get($this->url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('parts.items', [['part_id' => $this->ram->id, 'code' => 'RAM', 'name' => 'Memory', 'unit' => 'pcs', 'quantity' => 3, 'types' => ['issue']]])
            ->where('parts.options.0.qty_on_hand', 2));

    // the ledger links the movement to the ticket
    $this->actingAs($this->helpdesk)->get("/parts/{$this->ram->id}")
        ->assertInertia(fn (Assert $page) => $page->where('movements.data.0.ticket.ticket_no', $this->ticket->ticket_no));
});

it('rejects more than the stock, an inactive part and an unknown part', function () {
    $inactive = createPart(['is_active' => false], stock: 3);

    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 6])->assertSessionHasErrors('quantity');
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $inactive->id, 'quantity' => 1])->assertSessionHasErrors('part_id');
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => 999999, 'quantity' => 1])->assertSessionHasErrors('part_id');
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 0])->assertSessionHasErrors('quantity');

    expect(StockMovement::whereNotNull('ticket_id')->count())->toBe(0);
});

it('returns parts to stock, at most what the ticket still holds', function () {
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 3]);

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
    app(AssignTicket::class)->handle($elsewhere, $this->tech->id, $this->helpdesk);
    $this->actingAs($this->tech)->post("/tickets/{$elsewhere->ulid}/parts", ['part_id' => $this->ram->id, 'quantity' => 1]);
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 1])->assertSessionHasErrors('quantity');
});

it('only lets staff who work on the ticket and may issue stock book parts', function () {
    $payload = ['part_id' => $this->ram->id, 'quantity' => 1];

    // a technician who is not on the ticket (parts.issue scope own) may not
    $this->actingAs(userWithRole('technician'))->post("{$this->url}/parts", $payload)->assertForbidden();

    // a customer account sees its ticket but nothing about stock
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($client)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts', null));
    $this->actingAs($client)->post("{$this->url}/parts", $payload)->assertForbidden();

    expect($this->ram->fresh()->qty_on_hand)->toBe(5);
});

it('stops booking parts once the ticket is closed or cancelled', function () {
    app(MoveTicket::class)->handle($this->ticket, 'cancel', $this->helpdesk, 'duplicate');

    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 1])->assertSessionHasErrors('part_id');
    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts.canIssue', false));
});

it('lends parts and puts in spares for a ticket, and takes them back after the job is closed', function () {
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 1, 'type' => 'loan'])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 2, 'type' => 'spare'])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 1, 'type' => 'receive'])->assertSessionHasErrors('type');

    expect($this->ram->fresh()->qty_on_hand)->toBe(2)
        ->and(StockMovement::where('ticket_id', $this->ticket->id)->orderBy('id')->get(['type', 'quantity'])->toArray())
        ->toBe([['type' => 'loan', 'quantity' => -1], ['type' => 'spare', 'quantity' => -2]]);

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('parts.types', ['issue', 'loan', 'spare'])
        ->where('parts.items.0.quantity', 3)
        ->where('parts.items.0.types', ['loan', 'spare']));

    // the job is closed; nothing more can be taken, but what was lent still comes back
    app(MoveTicket::class)->handle($this->ticket, 'start', $this->tech);
    app(MoveTicket::class)->handle($this->ticket, 'resolve', $this->tech);
    app(MoveTicket::class)->handle($this->ticket, 'approve', userWithRole('admin_company'));

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('parts.canIssue', false)->where('parts.canReturn', true));
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 1, 'type' => 'loan'])->assertSessionHasErrors('part_id');
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 3])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$this->url}/parts/return", ['part_id' => $this->ram->id, 'quantity' => 1])->assertSessionHasErrors('quantity');

    expect($this->ram->fresh()->qty_on_hand)->toBe(5);
});

it('drops the parts section when the inventory module is switched off', function () {
    Feature::for($this->tenant)->deactivate(Modules::feature('inventory'));

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts', null));
    $this->actingAs($this->tech)->post("{$this->url}/parts", ['part_id' => $this->ram->id, 'quantity' => 1])->assertNotFound();
});
