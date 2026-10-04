<?php

use App\Modules\Service\Actions\AssignTicket;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician');
    $this->ticket = openTicket($this->helpdesk, [
        'title' => 'Printer jam',
        'customer_id' => createCustomer(['name' => 'Secret Customer'])->id,
        'contact_name' => 'Khun Somsri',
        'contact_phone' => '081-000-0000',
        'device_name' => 'Printer',
        'device_brand' => 'HP',
        'device_serial' => 'SN-ABC-123',
    ]);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);
});

it('lets anyone follow a repair by its exact number or serial, without signing in', function () {
    $this->get('/track')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Service/Track')
        ->where('askCompany', true)
        ->where('results', []));

    $this->get("/track?company=DEFAULT&q={$this->ticket->ticket_no}")->assertInertia(fn (Assert $page) => $page
        ->where('company', 'Default')
        ->where('results.0.ticket_no', $this->ticket->ticket_no)
        ->where('results.0.title', 'Printer jam')
        ->where('results.0.device', 'Printer HP')
        ->where('results.0.step', 1)
        ->where('results.0.state', 'active')
        // Nothing about the customer or the people.
        ->where('results.0', fn ($row) => collect($row)->keys()->sort()->values()->all() === ['at', 'device', 'state', 'status', 'step', 'ticket_no', 'title']));

    $this->get('/track?company=default&q=sn-abc-123')->assertInertia(fn (Assert $page) => $page->where('results.0.ticket_no', $this->ticket->ticket_no));
    // Exact only: part of a serial finds nothing.
    $this->get('/track?company=default&q=SN-ABC')->assertInertia(fn (Assert $page) => $page->where('searched', true)->where('results', []));
});

it('searches only the company asked for', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, fn () => openTicket(userWithRole('helpdesk', [], $other), ['title' => 'Their job']));
    expect($theirs->ticket_no)->toBe($this->ticket->ticket_no); // numbers repeat between companies

    $this->get("/track?company=default&q={$this->ticket->ticket_no}")
        ->assertInertia(fn (Assert $page) => $page->where('results', fn ($rows) => collect($rows)->pluck('title')->all() === ['Printer jam']));
    $this->get("/track?company=other&q={$theirs->ticket_no}")
        ->assertInertia(fn (Assert $page) => $page->where('results', fn ($rows) => collect($rows)->pluck('title')->all() === ['Their job']));
    $this->get("/track?company=nobody&q={$this->ticket->ticket_no}")
        ->assertInertia(fn (Assert $page) => $page->where('companyUnknown', true)->where('results', []));
});

it('uses the signed-in user\'s company without asking for it', function () {
    $this->actingAs($this->tech)->get("/track?q={$this->ticket->ticket_no}")
        ->assertInertia(fn (Assert $page) => $page->where('askCompany', false)->where('results.0.ticket_no', $this->ticket->ticket_no));
});
