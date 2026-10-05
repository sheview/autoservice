<?php

use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Support\CompanyCodes;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CompanyCodes::forget();
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician');
    $this->ticket = openTicket($this->helpdesk, [
        'title' => 'Printer jam',
        'customer_id' => createCustomer(['name' => 'Secret Customer'])->id,
        'contact_name' => 'Khun Somsri',
        'contact_phone' => '081-000-0000',
        'device_name' => 'Printer',
        'device_serial' => 'SN-ABC-123',
    ]);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);

    // Company 002 has a ticket with the very same number inside.
    $this->other = createTenant('other');
    $this->theirs = asTenant($this->other, fn () => openTicket(userWithRole('helpdesk', [], $this->other), ['title' => 'Their job', 'device_serial' => 'SN-ABC-123']));

    $this->search = fn (string $query) => $this->get('/track?'.$query);
    $this->found = fn (Assert $page) => $page->where('results', fn ($rows) => count($rows) === 1);
});

it('finds the ticket of the right company, by the number with its code or by a code typed in', function () {
    expect($this->ticket->getRawOriginal('ticket_no'))->toBe($this->theirs->getRawOriginal('ticket_no'))
        ->and($this->ticket->ticket_no)->toBe('TK001-'.substr($this->ticket->getRawOriginal('ticket_no'), 3))
        ->and($this->theirs->ticket_no)->toStartWith('TK002-');

    $raw = $this->ticket->getRawOriginal('ticket_no');
    ($this->search)('q='.urlencode(' '.strtolower($this->ticket->ticket_no).' '))->assertInertia(fn (Assert $page) => $page
        ->component('Service/Track')
        ->where('askCompany', true)
        ->where('results.0.ticket_no', $this->ticket->ticket_no)
        ->where('results.0.state', 'received'));
    ($this->search)("q={$this->theirs->ticket_no}")->assertInertia(fn (Assert $page) => $page->where('results.0.ticket_no', $this->theirs->ticket_no));

    // The old form (printed before) with the company typed in: subdomain name or number.
    ($this->search)("company=OTHER&q={$raw}")->assertInertia(fn (Assert $page) => $page->where('results.0.ticket_no', $this->theirs->ticket_no));
    ($this->search)("company=1&q={$raw}")->assertInertia(fn (Assert $page) => $page->where('results.0.ticket_no', $this->ticket->ticket_no));
    // The old form alone cannot tell the company.
    ($this->search)("q={$raw}")->assertInertia(fn (Assert $page) => $page->where('results', []));
});

it('shows a customer only where the job stands', function () {
    $this->ticket->forceFill(['customer_message' => 'รออะไหล่ 2 วัน'])->save();

    ($this->search)("q={$this->ticket->ticket_no}")->assertInertia(fn (Assert $page) => $page
        ->where('results.0.message', 'รออะไหล่ 2 วัน')
        ->where('results.0', fn ($row) => collect($row)->keys()->sort()->values()->all() === ['message', 'state', 'step', 'ticket_no', 'updated_at']));
});

it('answers the same whatever is wrong', function () {
    $raw = $this->ticket->getRawOriginal('ticket_no');
    $answers = collect([
        'company=nobody&q='.$raw,          // no such company
        'company=999&q='.$raw,             // no such code
        'q=TK999-2569-00001',              // code in the number unknown
        'company=default&q=TK-2569-99999', // no such ticket
        'q=TK1-2569-00001',                // not a number at all
    ])->map(fn (string $query) => ($this->search)($query)->viewData('page')['props'])
        ->map(fn (array $props) => [$props['searched'], $props['results']]);

    expect($answers->unique()->values()->all())->toBe([[true, []]]);
});

it('keeps a signed-in user to their own company, whatever code is typed', function () {
    $raw = $this->ticket->getRawOriginal('ticket_no');
    $this->actingAs($this->tech)->get("/track?company=other&q={$raw}")
        ->assertInertia(fn (Assert $page) => $page->where('askCompany', false)->where('results.0.ticket_no', $this->ticket->ticket_no));
    $this->actingAs($this->tech)->get("/track?q={$this->theirs->ticket_no}")
        ->assertInertia(fn (Assert $page) => $page->where('results', []));
});

it('gives a serial number its open jobs, or only the latest one', function () {
    ($this->search)('company=default&q=sn-abc-123')->assertInertia(fn (Assert $page) => $page->where('results.0.ticket_no', $this->ticket->ticket_no));

    $this->ticket->forceFill(['status' => Ticket::STATUS_CLOSED])->save();
    $older = openTicket($this->helpdesk, ['device_serial' => 'SN-ABC-123']);
    $older->forceFill(['status' => Ticket::STATUS_CLOSED])->save();
    ($this->search)('company=default&q=SN-ABC-123')->assertInertia(fn (Assert $page) => $page
        ->where('results', fn ($rows) => collect($rows)->pluck('ticket_no')->all() === [$older->ticket_no]));
});
