<?php

use App\Modules\Tenancy\Support\CompanyCodes;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CompanyCodes::forget();
    config(['app.url' => 'http://localhost', 'tenancy.public_links' => 'path']);
    $this->helpdesk = userWithRole('helpdesk');
    $this->ticket = openTicket($this->helpdesk, ['title' => 'Printer jam', 'contact_name' => 'Khun Somsri']);
    $this->token = $this->ticket->tracking_token;

    $this->other = createTenant('other');
    $this->theirs = asTenant($this->other, fn () => openTicket(userWithRole('helpdesk', [], $this->other)));
});

it('gives every ticket a long random tracking link the staff can send', function () {
    expect($this->token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($this->theirs->tracking_token)->not->toBe($this->token);

    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('tracking.url', "http://localhost/t/001/track/{$this->token}"));

    config(['tenancy.public_links' => 'subdomain', 'tenancy.central_domains' => ['example.test']]);
    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('tracking.url', "http://default.example.test/track/{$this->token}"));
});

it('opens the ticket of its link without signing in, and nothing else', function () {
    $this->get("/t/001/track/{$this->token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Service/TrackLink')
        ->where('company', 'Default')
        ->where('ticket.ticket_no', $this->ticket->ticket_no)
        ->where('ticket.state', 'received')
        ->where('ticket', fn ($t) => collect($t)->keys()->sort()->values()->all() === ['message', 'state', 'step', 'ticket_no', 'updated_at']));

    // On the company's own host.
    $this->get("http://default.localhost/track/{$this->token}")->assertInertia(fn (Assert $page) => $page->where('ticket.ticket_no', $this->ticket->ticket_no));
});

it('never opens a link through another company', function () {
    $notFound = fn ($response) => $response->assertInertia(fn (Assert $page) => $page->where('ticket', null)->where('company', null));

    $notFound($this->get("/t/002/track/{$this->token}"));                       // code of another company
    $notFound($this->get("http://other.localhost/track/{$this->token}"));       // host of another company
    $notFound($this->actingAs(userWithRole('helpdesk', [], $this->other))->get("/track/{$this->token}")); // signed in elsewhere
    $notFound($this->get('/t/001/track/'.str_repeat('a', 40)));                 // no such token
    $notFound($this->get('/t/001/track/short'));                               // not a token at all
    $notFound($this->get("/t/999/track/{$this->token}"));                       // no such company
});

it('stops an old link once a new one is made, by staff who may update the ticket', function () {
    $this->actingAs(userWithRole('user'))->post("/tickets/{$this->ticket->ulid}/tracking-token")->assertForbidden();

    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/tracking-token")->assertSessionHasNoErrors();
    $fresh = $this->ticket->fresh()->tracking_token;
    expect($fresh)->not->toBe($this->token);

    $this->get("/t/001/track/{$this->token}")->assertInertia(fn (Assert $page) => $page->where('ticket', null));
    $this->get("/t/001/track/{$fresh}")->assertInertia(fn (Assert $page) => $page->where('ticket.ticket_no', $this->ticket->ticket_no));
});
