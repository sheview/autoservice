<?php

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Holiday;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Friday 16:00, Bangkok
    $this->travelTo('2026-06-12 16:00');

    allowAllBranches('helpdesk'); // a head-office dispatcher
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Helpdesk Here']);
    $this->branch = Branch::create(['code' => 'BKK', 'name' => 'Bangkok']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One', 'branch_id' => $this->branch->id]);

    $this->customer = createCustomer(['name' => 'Acme']);
    $this->asset = createAsset(createAssetCategory(), ['customer_id' => $this->customer->id, 'branch_id' => $this->branch->id]);
    $this->contract = createContract($this->customer, [
        'service_window' => '8x5',
        'slas' => ['critical' => ['response_minutes' => 60, 'resolve_minutes' => 240]],
    ]);
    $this->contract->contractAssets()->create(['asset_id' => $this->asset->id]);
});

function ticketPayload(array $overrides = []): array
{
    return $overrides + [
        'customer_id' => test()->customer->id,
        'asset_id' => test()->asset->id,
        'contract_id' => test()->contract->id,
        'title' => 'Switch down',
        'priority' => 'critical',
        'source' => 'phone',
        'contact_name' => 'คุณสมศรี',
    ];
}

it('opens a ticket with a number, the contract SLA and business-hour due times', function () {
    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload())->assertSessionHasNoErrors();

    $ticket = Ticket::first();
    expect($ticket->ticket_no)->toBe('TK001-2569-00001')
        ->and($ticket->status)->toBe(Ticket::STATUS_NEW)
        ->and($ticket->branch_id)->toBe($this->branch->id)
        ->and($ticket->service_window)->toBe('8x5')
        ->and($ticket->response_minutes)->toBe(60)
        // Friday 16:00 + 1h = Friday 17:00; + 4h = Friday 17:00 then Monday 08:00-11:00
        ->and($ticket->response_due_at->format('Y-m-d H:i'))->toBe('2026-06-12 17:00')
        ->and($ticket->resolve_due_at->format('Y-m-d H:i'))->toBe('2026-06-15 11:00')
        ->and($ticket->events()->pluck('type')->all())->toBe(['created']);

    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload(['title' => 'Second']));
    expect(Ticket::latest('id')->first()->ticket_no)->toBe('TK001-2569-00002');
});

it('skips the company holidays when working out due times', function () {
    Holiday::create(['date' => '2026-06-15', 'name' => 'วันหยุดชดเชย']);

    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload());

    expect(Ticket::first()->resolve_due_at->format('Y-m-d H:i'))->toBe('2026-06-16 11:00');
});

it('opens an out-of-contract ticket without SLA', function () {
    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload(['contract_id' => null]))->assertSessionHasNoErrors();

    $ticket = Ticket::first();
    expect($ticket->contract_id)->toBeNull()
        ->and($ticket->response_due_at)->toBeNull()
        ->and($ticket->resolve_due_at)->toBeNull();

    // no SLA for the chosen priority either
    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload(['priority' => 'low']));
    expect(Ticket::latest('id')->first()->only(['contract_id', 'resolve_due_at']))
        ->toBe(['contract_id' => $this->contract->id, 'resolve_due_at' => null]);
});

it('rejects a contract that does not cover the asset and an asset of another customer', function () {
    $other = createCustomer();
    $otherContract = createContract($other);
    $uncovered = createAsset(createAssetCategory(), ['customer_id' => $this->customer->id]);

    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload(['contract_id' => $otherContract->id]))
        ->assertSessionHasErrors('contract_id');
    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload(['asset_id' => $uncovered->id]))
        ->assertSessionHasErrors('contract_id');
    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload(['customer_id' => $other->id, 'contract_id' => null]))
        ->assertSessionHasErrors('asset_id');

    expect(Ticket::count())->toBe(0);
});

it('lets helpdesk assign on opening but not a user without tickets.assign', function () {
    $this->actingAs($this->helpdesk)->post('/tickets', ticketPayload(['assignee_id' => $this->tech->id]))->assertSessionHasNoErrors();

    $ticket = Ticket::first();
    expect($ticket->status)->toBe(Ticket::STATUS_ASSIGNED)
        ->and($ticket->assignee_id)->toBe($this->tech->id)
        ->and($ticket->events()->pluck('body', 'type')->all())->toBe(['created' => null, 'assigned' => 'Tech One']);

    $this->actingAs(userWithRole('user', ['branch_id' => $this->branch->id]))->post('/tickets', ticketPayload(['assignee_id' => $this->tech->id]))
        ->assertSessionHasErrors('assignee_id');
});

it('offers the customer\'s people as the person reporting, or our staff without a customer', function () {
    userWithRole('customer_it', ['name' => 'Acme IT', 'phone' => '081-111-1111', 'customer_id' => $this->customer->id]);
    userWithRole('customer_it', ['name' => 'Beta IT', 'customer_id' => createCustomer(['name' => 'Beta'])->id]);
    userWithRole('customer_it', ['name' => 'Acme Gone', 'customer_id' => $this->customer->id, 'is_active' => false]);

    $this->actingAs($this->helpdesk)->get("/tickets/create?customer_id={$this->customer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('contactPeople', [['id' => User::where('name', 'Acme IT')->value('id'), 'name' => 'Acme IT', 'phone' => '081-111-1111', 'position' => null]]));

    $this->actingAs($this->helpdesk)->get('/tickets/create')
        ->assertInertia(fn (Assert $page) => $page
            ->where('contactPeople', fn ($people) => collect($people)->pluck('name')->sort()->values()->all() === ['Helpdesk Here', 'Tech One']));
});

it('fills the form from an asset and offers its covering contracts', function () {
    $this->actingAs($this->helpdesk)->get("/tickets/create?asset={$this->asset->ulid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Service/Tickets/Create')
            ->where('preset.asset.id', $this->asset->id)
            ->where('preset.customer_id', $this->customer->id)
            ->where('contracts.0.id', $this->contract->id)
            ->where('contracts.0.slas.critical', ['response_minutes' => 60, 'resolve_minutes' => 240])
            ->where('assignees', fn ($users) => collect($users)->pluck('name')->contains('Tech One')));

    $this->actingAs($this->helpdesk)->get("/tickets/create?customer_id={$this->customer->id}&asset_search={$this->asset->asset_code}")
        ->assertInertia(fn (Assert $page) => $page->where('assetOptions.0.id', $this->asset->id));
});

it('shows the tickets of an asset on the asset page', function () {
    openTicket($this->helpdesk, ticketPayload());

    $this->actingAs($this->helpdesk)->get("/assets/{$this->asset->ulid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('tickets.0.ticket_no', 'TK001-2569-00001')
            ->where('can.openTicket', true));
});

it('forbids opening tickets without tickets.create', function () {
    $nobody = User::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($nobody)->get('/tickets/create')->assertForbidden();
    $this->actingAs($nobody)->post('/tickets', ticketPayload())->assertForbidden();
});
