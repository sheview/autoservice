<?php

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Models\PersonalEvent;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

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

it('keeps an appointment after 2038 instead of failing the save', function () {
    $visit = ($this->assigned)(['title' => 'Far away']);

    $this->actingAs($this->tech)->post("/tickets/{$visit->ulid}/appointment", ['appointment_at' => '2040-05-01T10:00'])->assertSessionHasNoErrors();
    expect($visit->fresh()->appointment_at->format('Y-m-d H:i'))->toBe('2040-05-01 10:00');
});

it('shows when the contracts the user may see start and end, never drafts or another company\'s', function () {
    $acme = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $starting = createContract($acme, ['contract_no' => 'MA-START', 'starts_on' => '2026-06-20', 'ends_on' => '2027-06-19']);
    $ending = createContract($acme, ['contract_no' => 'MA-END', 'starts_on' => '2025-06-26', 'ends_on' => '2026-06-25']);
    createContract($acme, ['contract_no' => 'MA-LATER', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    createContract($acme, ['contract_no' => 'MA-DRAFT', 'status' => 'draft', 'starts_on' => '2026-06-15', 'ends_on' => '2027-06-14']);
    $theirs = asTenant(createTenant('other'), fn () => createContract(createCustomer(), ['contract_no' => 'MA-THEIRS', 'starts_on' => '2026-06-15']));

    $contracts = fn (User $user) => $this->actingAs($user)->get('/my-work')->assertOk()->viewData('page')['props']['events'];
    $milestones = fn (User $user) => collect($contracts($user))->where('kind', 'contract')->map(fn ($e) => [$e['date'], $e['when'], $e['title']])->values()->all();

    // An admin sees every contract of the company: on the calendar only, not in the to-do list.
    expect($milestones(userWithRole('admin_company')))->toBe([
        ['2026-06-20', 'contract_start', 'MA-START Acme'],
        ['2026-06-25', 'contract_end', 'MA-END Acme'],
    ]);
    expect(collect($contracts(userWithRole('admin_company')))->firstWhere('when', 'contract_start')['href'])
        ->toBe(route('contract.contracts.show', $starting));

    // A technician (scope project) sees only the projects of their team.
    expect($milestones($this->tech))->toBe([]);
    $this->actingAs(userWithRole('admin_company'))->put("/contracts/{$ending->id}/members", ['user_ids' => [$this->tech->id]])->assertSessionHasNoErrors();
    expect($milestones($this->tech))->toBe([['2026-06-25', 'contract_end', 'MA-END Acme']]);
    $this->actingAs($this->tech)->get('/my-work')->assertInertia(fn (Assert $page) => $page->where('todo', []));
    expect($theirs->contract_no)->toBe('MA-THEIRS');
});

it('shows the team\'s PM on the same calendar, by customer and technician, to who may see PM', function () {
    $customer = createCustomer(['name' => 'Acme']);
    $contract = createContract($customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => createAsset(createAssetCategory(), ['customer_id' => $customer->id])->id]);
    createPmTemplate(['name' => 'General', 'items' => [['key' => 'look', 'label' => 'Look', 'type' => 'check']]]);
    $plan = pmPlanWithAllRounds(['contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 1, 'assignee_id' => $this->other->id]);
    $visit = $plan->visits()->whereBetween('due_on', ['2026-06-01', '2026-06-30'])->first();

    $this->actingAs($this->helpdesk)->get('/my-work?view=pm')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('view', 'pm')
        ->where('views', fn ($views) => collect($views)->contains('pm'))
        ->where('events', fn ($events) => collect($events)->contains(fn ($e) => $e['kind'] === 'pm' && $e['date'] === $visit->due_on->toDateString()
            && $e['when'] === 'pm_due' && str_contains($e['notes'], 'Tech Two')))
        ->has('options.customers')
        ->has('options.assignees'));

    // Filtered to another technician: none of these.
    $this->actingAs($this->helpdesk)->get("/my-work?view=pm&assignee={$this->tech->id}")
        ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events) => collect($events)->where('kind', 'pm')->isEmpty()));
    // The to-do list stays the user's own whatever the view.
    $this->actingAs($this->other)->get('/my-work?view=pm')
        ->assertInertia(fn (Assert $page) => $page->where('todo', fn ($todo) => collect($todo)->every(fn ($e) => $e['kind'] !== 'pm_projected')));
});

it('falls back to the user\'s own work for a view they may not open', function () {
    $this->actingAs($this->tech)->get('/my-work?view=nonsense')->assertInertia(fn (Assert $page) => $page->where('view', 'mine'));

    // Without the right to see PM work orders, no PM view, whatever is asked.
    $noPm = userWithRole('helpdesk');
    $noPm->roles->first()->revokePermissionTo('pm-visits.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($noPm->fresh())->get('/my-work?view=pm')->assertInertia(fn (Assert $page) => $page
        ->where('view', 'mine')
        ->where('views', fn ($views) => ! collect($views)->contains('pm')));
});
