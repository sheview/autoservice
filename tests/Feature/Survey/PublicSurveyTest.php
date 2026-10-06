<?php

use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/**
 * A closed ticket in the current tenant; returns its survey.
 */
function closedTicketSurvey(string $title = 'Printer jam'): TicketSurvey
{
    $helpdesk = userWithRole('helpdesk');
    $tech = userWithRole('technician');
    $ticket = openTicket($helpdesk, ['title' => $title]);
    app(AssignTicket::class)->handle($ticket, $tech->id, $helpdesk);
    foreach (['start', 'resolve'] as $move) {
        app(MoveTicket::class)->handle($ticket, $move, $tech);
    }
    app(MoveTicket::class)->handle($ticket, 'approve', userWithRole('admin_company'));

    return TicketSurvey::where('ticket_id', $ticket->id)->sole();
}

beforeEach(function () {
    Notification::fake();

    $this->survey = closedTicketSurvey();
    $this->url = "/s/{$this->tenant->ulid}/{$this->survey->token}";
});

it('opens the public link without signing in and takes one answer', function () {
    $this->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Survey/Public')
        ->where('company', 'Default')
        ->where('survey', ['ticket_no' => $this->survey->ticket_no, 'ticket_title' => 'Printer jam', 'score' => null, 'answered' => false])
        ->where('action', url($this->url))
        // a visitor is nobody: no user, no tenant data, no menu
        ->where('auth.user', null)
        ->where('navigation', []));

    $this->post($this->url, ['score' => 0])->assertSessionHasErrors('score');
    $this->post($this->url, ['score' => 3, 'comment' => str_repeat('x', 2001)])->assertSessionHasErrors('comment');
    $this->post($this->url, ['score' => 5, 'comment' => 'บริการดีมาก', 'name' => ' คุณสมชาย '])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->survey->fresh()->only(['score', 'comment', 'answered_by', 'answered_name']))
        ->toBe(['score' => 5, 'comment' => 'บริการดีมาก', 'answered_by' => null, 'answered_name' => 'คุณสมชาย'])
        ->and($this->survey->fresh()->answered_at)->not->toBeNull();

    // the link still opens, shows the score and does not take a second answer
    $this->get($this->url)->assertInertia(fn (Assert $page) => $page->where('survey.answered', true)->where('survey.score', 5));
    $this->post($this->url, ['score' => 1])->assertSessionHasErrors('score');
    expect($this->survey->fresh()->score)->toBe(5);
});

it('returns 404 for a wrong token, tenant or a switched-off module', function () {
    $wrongToken = str_repeat('a', 40);
    $other = createTenant('other');
    $platform = Tenant::create(['name' => 'Platform', 'slug' => 'platform', 'subdomain' => 'admin', 'is_platform' => true]);

    $this->get("/s/{$this->tenant->ulid}/{$wrongToken}")->assertNotFound();
    $this->get("/s/{$this->tenant->ulid}/short")->assertNotFound();
    // the right token with another tenant in the link finds nothing
    $this->get("/s/{$other->ulid}/{$this->survey->token}")->assertNotFound();
    $this->post("/s/{$other->ulid}/{$this->survey->token}", ['score' => 1])->assertNotFound();
    $this->get("/s/{$platform->ulid}/{$this->survey->token}")->assertNotFound();
    $this->get('/s/'.str_repeat('0', 26)."/{$this->survey->token}")->assertNotFound();

    Feature::for($this->tenant)->deactivate(Modules::feature('survey'));
    $this->get($this->url)->assertNotFound();
    $this->post($this->url, ['score' => 5])->assertNotFound();
    Feature::for($this->tenant)->activate(Modules::feature('survey'));

    $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
    $this->get($this->url)->assertNotFound();

    expect($this->survey->fresh()->score)->toBeNull();
});

it('keeps surveys per tenant', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, fn () => closedTicketSurvey('Their job'));

    // their link works for them, and a signed-in user of this tenant can still open it as a visitor
    $this->get("/s/{$other->ulid}/{$theirs->token}")->assertInertia(fn (Assert $page) => $page
        ->where('company', 'Other')->where('survey.ticket_title', 'Their job'));
    $this->actingAs(userWithRole('helpdesk'))->post("/s/{$other->ulid}/{$theirs->token}", ['score' => 4])->assertSessionHasNoErrors();
    asTenant($other, fn () => expect($theirs->fresh()->only(['score', 'answered_by']))->toBe(['score' => 4, 'answered_by' => null]));

    // but nothing of theirs shows up here
    $this->actingAs(userWithRole('admin_company'))->get('/surveys')->assertInertia(fn (Assert $page) => $page
        ->where('surveys.total', 1)->where('surveys.data.0.ticket_title', 'Printer jam')->where('summary.answered', 0));
    expect(TicketSurvey::pluck('ticket_title')->all())->toBe(['Printer jam'])
        ->and(TicketSurvey::whereKey($theirs->id)->update(['score' => 1]))->toBe(0);
});
