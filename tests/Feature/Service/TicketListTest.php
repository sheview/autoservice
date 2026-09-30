<?php

use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-06-15 09:00');

    allowAllBranches('helpdesk'); // a head-office dispatcher
    $this->helpdesk = userWithRole('helpdesk');
    $this->north = Branch::create(['code' => 'N', 'name' => 'North']);
    $this->tech = userWithRole('technician', ['branch_id' => $this->north->id]);

    $customer = createCustomer(['name' => 'Acme']);
    $northAsset = createAsset(createAssetCategory(), ['customer_id' => $customer->id, 'branch_id' => $this->north->id]);
    $southAsset = createAsset(createAssetCategory(), ['customer_id' => $customer->id, 'branch_id' => Branch::create(['code' => 'S', 'name' => 'South'])->id]);
    $contract = createContract($customer, ['service_window' => '24x7', 'slas' => [
        'critical' => ['response_minutes' => 30, 'resolve_minutes' => 120],
        'low' => ['response_minutes' => 480, 'resolve_minutes' => 2880],
    ]]);
    $contract->contractAssets()->create(['asset_id' => $northAsset->id]);
    $contract->contractAssets()->create(['asset_id' => $southAsset->id]);

    $open = fn (string $title, array $extra = []) => openTicket($this->helpdesk, $extra + [
        'title' => $title, 'customer_id' => $customer->id, 'asset_id' => $northAsset->id, 'contract_id' => $contract->id,
    ]);

    $this->urgent = $open('Urgent north', ['priority' => 'critical']);
    $this->calm = $open('Calm north', ['priority' => 'low', 'assignee_id' => $this->tech->id]);
    $this->south = $open('South job', ['priority' => 'low', 'asset_id' => $southAsset->id]);
    $this->done = $open('Done job', ['priority' => 'low']);
    $this->done->update(['status' => Ticket::STATUS_CLOSED]);
});

function ticketTitles($test, string $query): array
{
    return collect($test->actingAs($test->helpdesk)->get("/tickets?{$query}")
        ->assertInertia(fn (Assert $page) => $page->component('Service/Tickets/Index'))
        ->viewData('page')['props']['tickets']['data'])->pluck('title')->all();
}

it('opens on running tickets and filters on the server', function () {
    expect(ticketTitles($this, ''))->toHaveCount(3)->not->toContain('Done job')
        ->and(ticketTitles($this, 'status=all'))->toHaveCount(4)
        ->and(ticketTitles($this, 'status=closed'))->toBe(['Done job'])
        ->and(ticketTitles($this, 'priority=critical'))->toBe(['Urgent north'])
        ->and(ticketTitles($this, 'assignee=none&sort=ticket_no&direction=asc'))->toBe(['Urgent north', 'South job'])
        ->and(ticketTitles($this, 'search=south'))->toBe(['South job'])
        ->and(ticketTitles($this, 'sort=priority&direction=desc'))->toBe(['Urgent north', 'South job', 'Calm north']);
});

it('finds tickets past or close to their SLA', function () {
    // 24x7: critical response due 09:30, resolve 11:00
    $this->travelTo('2026-06-15 09:45');
    expect(ticketTitles($this, 'sla=breached'))->toBe(['Urgent north'])
        ->and(ticketTitles($this, 'sla=due_soon'))->toBe(['Urgent north']);

    $this->actingAs($this->helpdesk)->get('/tickets?priority=critical')
        ->assertInertia(fn (Assert $page) => $page->where('tickets.data.0.sla', ['response' => 'breached', 'resolve' => 'pending']));
});

it('shows a technician the tickets of their branch and their own', function () {
    $titles = collect($this->actingAs($this->tech)->get('/tickets?assignee=')
        ->viewData('page')['props']['tickets']['data'])->pluck('title')->sort()->values()->all();

    expect($titles)->toBe(['Calm north', 'Urgent north']);

    $this->actingAs($this->tech)->get('/tickets?assignee=me')
        ->assertInertia(fn (Assert $page) => $page->where('tickets.total', 1)->where('tickets.data.0.title', 'Calm north'));
});
