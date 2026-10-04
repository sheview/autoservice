<?php

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Models\PersonalEvent;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Wednesday 10 June 2026, 10:00 Bangkok
    $this->travelTo('2026-06-10 10:00');
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->other = userWithRole('technician', ['name' => 'Tech Two']);

    $this->assigned = function (array $attributes = []) {
        $ticket = openTicket($this->helpdesk, $attributes);
        app(AssignTicket::class)->handle($ticket, $this->tech->id, $this->helpdesk);

        return $ticket->fresh();
    };
});

it('puts assigned tickets on their appointment day, else when due, and lists what is still to do', function () {
    $visit = ($this->assigned)(['title' => 'Site visit']);
    $this->actingAs($this->tech)->post("/tickets/{$visit->ulid}/appointment", ['appointment_at' => '2026-06-15T13:30'])->assertSessionHasNoErrors();
    expect($visit->fresh()->appointment_at->timezone('Asia/Bangkok')->format('Y-m-d H:i'))->toBe('2026-06-15 13:30');

    $late = ($this->assigned)(['title' => 'Late one']);
    $late->forceFill(['resolve_due_at' => '2026-06-08 10:00'])->save();

    $this->actingAs($this->tech)->get('/my-work')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Service/MyWork/Index')
        ->where('month', '2026-06')
        ->where('from', '2026-05-31')
        ->where('to', '2026-07-04')
        ->where('events', fn ($events) => collect($events)->firstWhere('title', "{$visit->ticket_no} Site visit")['date'] === '2026-06-15'
            && collect($events)->firstWhere('title', "{$visit->ticket_no} Site visit")['time'] === '13:30'
            && collect($events)->firstWhere('title', "{$visit->ticket_no} Site visit")['when'] === 'appointment')
        ->where('todo.0.title', "{$late->ticket_no} Late one")
        ->where('todo.0.overdue', true));

    // Somebody else's ticket is not on my calendar, and they cannot set my appointment.
    $this->actingAs($this->other)->get('/my-work')->assertInertia(fn (Assert $page) => $page->where('todo', []));
    $this->actingAs($this->other)->post("/tickets/{$visit->ulid}/appointment", ['appointment_at' => '2026-06-16T09:00'])->assertForbidden();
});

it('keeps own appointments to their owner', function () {
    $this->actingAs($this->tech)->post('/my-work/events', [
        'title' => 'Team meeting', 'date' => '2026-06-12', 'all_day' => false, 'start_time' => '09:00', 'end_time' => '10:00',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post('/my-work/events', ['title' => 'Day off', 'date' => '2026-06-19', 'all_day' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post('/my-work/events', ['title' => 'Bad', 'date' => '2026-06-12', 'all_day' => false, 'start_time' => '10:00', 'end_time' => '09:00'])
        ->assertSessionHasErrors('end_time');

    $meeting = PersonalEvent::where('title', 'Team meeting')->first();
    expect($meeting->starts_at->timezone('Asia/Bangkok')->format('Y-m-d H:i'))->toBe('2026-06-12 09:00');

    $this->actingAs($this->tech)->get('/my-work')->assertInertia(fn (Assert $page) => $page
        ->where('events', fn ($events) => collect($events)->where('kind', 'personal')->pluck('title')->all() === ['Team meeting', 'Day off']
            && collect($events)->firstWhere('title', 'Day off')['all_day'] === true));

    // Nobody else sees or touches it, not even an admin.
    $this->actingAs(userWithRole('admin_company'))->get('/my-work')
        ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events) => collect($events)->where('kind', 'personal')->isEmpty()));
    $this->actingAs($this->other)->put("/my-work/events/{$meeting->id}", ['title' => 'Mine now', 'date' => '2026-06-12', 'all_day' => true])->assertNotFound();
    $this->actingAs($this->other)->delete("/my-work/events/{$meeting->id}")->assertNotFound();

    $this->actingAs($this->tech)->put("/my-work/events/{$meeting->id}", ['title' => 'Team meeting (moved)', 'date' => '2026-06-13', 'all_day' => true])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->delete("/my-work/events/{$meeting->id}")->assertSessionHasNoErrors();
    expect(PersonalEvent::count())->toBe(1);
});

it('reminds the borrower of what they must give back', function () {
    $request = CheckoutRequest::create([
        'request_no' => 'RQ-1', 'status' => CheckoutRequest::STATUS_FULFILLED, 'borrower_user_id' => $this->tech->id, 'borrower_name' => 'Tech One',
    ]);
    $request->items()->create([
        'item_type' => CheckoutItem::TYPE_ASSET, 'item_name' => 'Laptop', 'checkout_type' => CheckoutItem::LOAN, 'qty_requested' => 1,
        'qty_fulfilled' => 1, 'status' => CheckoutItem::STATUS_FULFILLED, 'due_return_date' => '2026-06-09',
    ]);

    $this->actingAs($this->tech)->get('/my-work')->assertInertia(fn (Assert $page) => $page
        ->where('todo.0.kind', 'loan')
        ->where('todo.0.overdue', true)
        ->where('todo.0.date', '2026-06-09'));
});

it('is for staff only', function () {
    $client = userWithRole('customer_it', ['customer_id' => createCustomer()->id]);
    $this->actingAs($client)->get('/my-work')->assertForbidden();
});
