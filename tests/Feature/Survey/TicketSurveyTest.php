<?php

use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Service\Models\Ticket;
use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Survey\Notifications\SurveyInvitation;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Notification::fake();

    $this->admin = userWithRole('admin_company', ['name' => 'Admin Here']);
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->acme = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $this->client = userWithRole('customer', ['name' => 'Acme IT', 'customer_id' => $this->acme->id]);

    // Opens a ticket for Acme and takes it to "resolved"; $closer then approves (closes) it.
    $this->closedTicket = function ($reporter = null, $closer = null, array $attributes = []): Ticket {
        $ticket = openTicket($reporter ?? $this->helpdesk, $attributes + ['title' => 'Printer jam', 'customer_id' => $this->acme->id]);
        app(AssignTicket::class)->handle($ticket, $this->tech->id, $this->helpdesk);
        app(MoveTicket::class)->handle($ticket, 'start', $this->tech);
        app(MoveTicket::class)->handle($ticket, 'resolve', $this->tech);
        if ($closer !== false) {
            app(MoveTicket::class)->handle($ticket, 'approve', $closer ?? $this->admin);
        }

        return $ticket;
    };
});

it('creates one survey when a ticket is closed, with the ticket details', function () {
    $open = ($this->closedTicket)(closer: false);
    expect(TicketSurvey::count())->toBe(0);

    $this->actingAs($this->admin)->post("/tickets/{$open->ulid}/move", ['action' => 'approve'])->assertSessionHasNoErrors();

    $survey = TicketSurvey::sole();
    expect($survey->only(['ticket_id', 'ticket_ulid', 'ticket_no', 'ticket_title', 'customer_id', 'assignee_id', 'score', 'answered_at', 'tenant_id']))->toBe([
        'ticket_id' => $open->id, 'ticket_ulid' => $open->ulid, 'ticket_no' => $open->ticket_no, 'ticket_title' => 'Printer jam',
        'customer_id' => $this->acme->id, 'assignee_id' => $this->tech->id, 'score' => null, 'answered_at' => null, 'tenant_id' => $this->tenant->id,
    ])
        ->and($survey->token)->toMatch('/^[0-9A-Za-z]{40}$/');
});

it('does not create surveys while the module is switched off', function () {
    Feature::for($this->tenant)->deactivate(Modules::feature('survey'));

    $ticket = ($this->closedTicket)();

    expect(TicketSurvey::count())->toBe(0);
    $this->actingAs($this->admin)->get("/tickets/{$ticket->ulid}")->assertInertia(fn (Assert $page) => $page->where('survey', null));
    $this->actingAs($this->admin)->post("/tickets/{$ticket->ulid}/survey", ['score' => 5])->assertNotFound();
    $this->actingAs($this->admin)->get('/surveys')->assertNotFound();
});

it('invites the customer who reported the ticket, unless they closed it themselves', function () {
    // staff closes a ticket the customer opened: the customer gets the link
    ($this->closedTicket)(reporter: $this->client);
    Notification::assertSentTo($this->client, SurveyInvitation::class, function (SurveyInvitation $invitation) {
        $mail = $invitation->toMail($this->client);

        return str_contains($mail->subject, $invitation->survey->ticket_no)
            && $mail->actionUrl === url("/s/{$this->tenant->ulid}/{$invitation->survey->token}");
    });
    Notification::assertSentToTimes($this->client, SurveyInvitation::class, 1);

    // the customer closes it: the survey is on the page in front of them
    ($this->closedTicket)(reporter: $this->client, closer: $this->client);
    Notification::assertSentToTimes($this->client, SurveyInvitation::class, 1);

    // a ticket opened by staff has nobody to e-mail
    ($this->closedTicket)();
    Notification::assertNotSentTo($this->helpdesk, SurveyInvitation::class);
    expect(TicketSurvey::count())->toBe(3);
});

it('lets the customer account rate its ticket on the ticket page, once', function () {
    $ticket = ($this->closedTicket)(reporter: $this->client, closer: $this->client);
    $url = "/tickets/{$ticket->ulid}";

    // a customer account answers, and never sees the public link
    $this->actingAs($this->client)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('survey.answered', false)->where('survey.canAnswer', true)->where('survey.url', null)->where('survey.qr', null));

    $this->actingAs($this->client)->post("{$url}/survey", ['score' => 6])->assertSessionHasErrors('score');
    $this->actingAs($this->client)->post("{$url}/survey", ['comment' => 'no score'])->assertSessionHasErrors('score');
    $this->actingAs($this->client)->post("{$url}/survey", ['score' => 4, 'comment' => ' ช่างมาเร็ว '])->assertSessionHasNoErrors();
    $this->actingAs($this->client)->post("{$url}/survey", ['score' => 1])->assertSessionHasErrors('score');

    expect(TicketSurvey::sole()->only(['score', 'comment', 'answered_by', 'answered_name']))
        ->toBe(['score' => 4, 'comment' => 'ช่างมาเร็ว', 'answered_by' => $this->client->id, 'answered_name' => 'Acme IT'])
        ->and(TicketSurvey::sole()->answered_at)->not->toBeNull();

    $this->actingAs($this->client)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('survey.answered', true)->where('survey.score', 4)->where('survey.canAnswer', false));

    // an account of another customer cannot see or rate the ticket
    $other = userWithRole('customer', ['customer_id' => createCustomer()->id]);
    $another = ($this->closedTicket)();
    $this->actingAs($other)->post("/tickets/{$another->ulid}/survey", ['score' => 1])->assertForbidden();
    expect(TicketSurvey::where('ticket_id', $another->id)->value('score'))->toBeNull();
});

it('shows staff the link to send while unanswered, then the answer', function () {
    $ticket = ($this->closedTicket)();
    $url = "/tickets/{$ticket->ulid}";
    $link = url("/s/{$this->tenant->ulid}/".TicketSurvey::sole()->token);

    // helpdesk may look and share, not answer
    $this->actingAs($this->helpdesk)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('survey.answered', false)
        ->where('survey.canAnswer', false)
        ->where('survey.url', $link)
        ->where('survey.qr', fn ($svg) => str_starts_with($svg, '<svg')));
    $this->actingAs($this->helpdesk)->post("{$url}/survey", ['score' => 5])->assertForbidden();

    // the technician who did the job sees nothing of it
    $this->actingAs($this->tech)->get($url)->assertInertia(fn (Assert $page) => $page->where('survey', null));
    $this->actingAs($this->tech)->post("{$url}/survey", ['score' => 5])->assertForbidden();

    // an admin may record the customer's answer (e.g. given on the phone)
    $this->actingAs($this->admin)->post("{$url}/survey", ['score' => 5])->assertSessionHasNoErrors();
    $this->actingAs($this->helpdesk)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('survey.score', 5)->where('survey.answered_name', 'Admin Here')->where('survey.url', null)->where('survey.qr', null));

    // a ticket that is not closed has no survey
    $open = ($this->closedTicket)(closer: false);
    $this->actingAs($this->admin)->get("/tickets/{$open->ulid}")->assertInertia(fn (Assert $page) => $page->where('survey', null));
    $this->actingAs($this->admin)->post("/tickets/{$open->ulid}/survey", ['score' => 5])->assertSessionHasErrors('score');
});

it('lists the surveys with search, filters, sort and a summary', function () {
    $beta = createCustomer(['name' => 'Beta']);
    $tech2 = userWithRole('technician', ['name' => 'Tech Two']);

    $good = ($this->closedTicket)(attributes: ['title' => 'Switch down']);
    $bad = ($this->closedTicket)(attributes: ['title' => 'Slow laptop', 'customer_id' => $beta->id]);
    ($this->closedTicket)(attributes: ['title' => 'No answer yet']);
    TicketSurvey::where('ticket_id', $bad->id)->update(['assignee_id' => $tech2->id]);

    $this->actingAs($this->admin)->post("/tickets/{$good->ulid}/survey", ['score' => 5, 'comment' => 'ยอดเยี่ยม']);
    $this->actingAs($this->admin)->post("/tickets/{$bad->ulid}/survey", ['score' => 2, 'comment' => 'รอนาน']);

    $get = fn (string $query) => $this->actingAs($this->helpdesk)->get("/surveys?{$query}");

    $get('')->assertInertia(fn (Assert $page) => $page->component('Survey/Index')
        ->where('surveys.total', 3)
        ->where('surveys.data.0.ticket_title', 'No answer yet')
        ->where('summary.sent', 3)
        ->where('summary.answered', 2)
        ->where('summary.response_rate', 67)
        ->where('summary.average', 3.5)
        ->where('summary.scores', fn ($scores) => collect($scores)->all() === [5 => 1, 4 => 0, 3 => 0, 2 => 1, 1 => 0])
        ->where('technicians', fn ($technicians) => collect($technicians)->pluck('name')->all() === ['Tech One', 'Tech Two'])
        ->where('can.viewTickets', true));

    $get('search=laptop')->assertInertia(fn (Assert $page) => $page->where('surveys.total', 1)->where('surveys.data.0.customer', 'Beta'));
    $get('search=ยอดเยี่ยม')->assertInertia(fn (Assert $page) => $page->where('surveys.total', 1)->where('surveys.data.0.score', 5));
    $get('status=pending')->assertInertia(fn (Assert $page) => $page->where('surveys.total', 1)->where('summary.average', null));
    $get('status=answered')->assertInertia(fn (Assert $page) => $page->where('surveys.total', 2)->where('summary.response_rate', 100));
    $get('score=2')->assertInertia(fn (Assert $page) => $page->where('surveys.total', 1)->where('surveys.data.0.assignee', 'Tech Two'));
    $get("customer_id={$beta->id}")->assertInertia(fn (Assert $page) => $page->where('surveys.total', 1));
    $get("assignee_id={$this->tech->id}")->assertInertia(fn (Assert $page) => $page->where('surveys.total', 2));
    // unanswered surveys stay last whichever way the score is sorted
    $get('sort=score&direction=asc')->assertInertia(fn (Assert $page) => $page
        ->where('surveys.data.0.score', 2)->where('surveys.data.2.score', null));
    $get('sort=score&direction=desc')->assertInertia(fn (Assert $page) => $page
        ->where('surveys.data.0.score', 5)->where('surveys.data.2.score', null));

    // technicians, office users and customer accounts do not see the results
    $this->actingAs($this->tech)->get('/surveys')->assertForbidden();
    $this->actingAs(userWithRole('user'))->get('/surveys')->assertForbidden();
    $this->actingAs($this->client)->get('/surveys')->assertForbidden();
});
