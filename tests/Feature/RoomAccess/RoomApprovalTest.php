<?php

use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Jobs\DeliverAlert;
use App\Modules\RoomAccess\Actions\PublishRoomRules;
use App\Modules\RoomAccess\Models\RoomAccessApproval;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomApprovalStep;
use App\Modules\RoomAccess\Models\RoomRuleAcceptance;
use App\Modules\RoomAccess\Models\ServerRoom;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Deciding on server room requests: approve, turn down with why, or send back for more
 * information; never one's own request, never a step out of turn (a named approver decides their
 * step only; a later step waits for the earlier); the alerts on the way; the history.
 */

beforeEach(function () {
    $this->travelTo('2026-11-02 09:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->desk2 = userWithRole('helpdesk', ['name' => 'Desk Two']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->room = ServerRoom::create(['customer_id' => createCustomer(['name' => 'Acme'])->id, 'name' => 'DC Room 1']);
    $this->v1 = app(PublishRoomRules::class)->handle($this->room, ['summary' => ['ห้ามนำอาหารเข้า'], 'effective_on' => '2026-11-01'], null, $this->admin);

    // A request sent by $user (the technician by default), waiting for approval.
    $this->pending = function ($user = null) {
        $user ??= $this->tech;
        $this->actingAs($user)->post('/room-access/requests', [
            'server_room_id' => $this->room->id, 'planned_start' => '2026-11-05 10:00', 'planned_end' => '2026-11-05 12:00',
            'purpose' => 'PM ประจำเดือน', 'people' => [['name' => $user->name]], 'submit' => true, 'accept' => true, 'version_id' => $this->v1->id,
        ])->assertSessionHasNoErrors();

        return RoomAccessRequest::latest('id')->first();
    };
    $this->decide = fn (RoomAccessRequest $request, string $decision, $user, ?string $note = null) => $this->actingAs($user)
        ->post("/room-access/requests/{$request->ulid}/decide", ['decision' => $decision, 'note' => $note]);
});

it('approves a request, with who and when in its history and approvals', function () {
    $request = ($this->pending)();
    expect($request->status)->toBe('pending');

    $this->actingAs($this->desk)->get("/room-access/requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('can.decide', true)->where('request.waiting_for.step', 1)->where('request.waiting_for.name', null));
    ($this->decide)($request, 'approve', $this->desk)->assertSessionHasNoErrors();

    $request->refresh();
    expect($request->status)->toBe('approved')->and($request->approved_at)->not->toBeNull()
        ->and(RoomAccessApproval::sole()->only(['step', 'decision', 'actor_name', 'round']))->toBe(['step' => 1, 'decision' => 'approved', 'actor_name' => 'Desk One', 'round' => 1])
        ->and($request->events()->pluck('action')->all())->toBe(['created', 'submitted', 'approved']);

    // Nothing more to decide.
    ($this->decide)($request, 'approve', $this->desk2)->assertSessionHasErrors('decision');
});

it('never lets anyone approve their own request, nor someone without the right', function () {
    $own = ($this->pending)($this->desk);

    $this->actingAs($this->desk)->get("/room-access/requests/{$own->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('can.decide', false)->where('can.own', true));
    ($this->decide)($own, 'approve', $this->desk)->assertForbidden();
    ($this->decide)(($this->pending)(), 'approve', userWithRole('technician'))->assertForbidden();

    expect(RoomAccessApproval::count())->toBe(0)->and($own->fresh()->status)->toBe('pending');
});

it('leaves a step named for someone to them, and the steps in their order', function () {
    RoomApprovalStep::create(['server_room_id' => $this->room->id, 'position' => 1, 'side' => 'company', 'approver_user_id' => $this->desk2->id]);
    RoomApprovalStep::create(['server_room_id' => $this->room->id, 'position' => 2, 'side' => 'company', 'approver_user_id' => $this->desk->id]);
    $request = ($this->pending)();

    // Step 2's approver cannot go first.
    ($this->decide)($request, 'approve', $this->desk)->assertForbidden();
    ($this->decide)($request, 'approve', $this->desk2)->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('pending');

    $this->actingAs($this->desk)->get('/room-access/requests?status=awaiting')->assertInertia(fn (Assert $page) => $page
        ->where('awaiting', 1)->where('requests.total', 1));
    ($this->decide)($request, 'approve', $this->desk)->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('approved')
        ->and(RoomAccessApproval::orderBy('id')->pluck('step')->all())->toBe([1, 2]);
});

it('waits for a step of the customer side, which nobody here can skip', function () {
    RoomApprovalStep::create(['server_room_id' => $this->room->id, 'position' => 1, 'side' => 'company']);
    RoomApprovalStep::create(['server_room_id' => $this->room->id, 'position' => 2, 'side' => 'customer', 'approver_name' => 'คุณสมชาย Acme']);
    $request = ($this->pending)();

    ($this->decide)($request, 'approve', $this->desk)->assertSessionHasNoErrors();
    ($this->decide)($request, 'approve', $this->admin)->assertForbidden();
    expect($request->fresh()->status)->toBe('pending');
});

it('turns a request down with why, or sends it back for more, after which it is sent again from the start', function () {
    $request = ($this->pending)();
    ($this->decide)($request, 'ask', $this->desk)->assertSessionHasErrors('note');
    ($this->decide)($request, 'ask', $this->desk, 'แนบใบสั่งงานของลูกค้าด้วย')->assertSessionHasNoErrors();

    expect($request->fresh()->only(['status', 'decision_note']))->toBe(['status' => 'draft', 'decision_note' => 'แนบใบสั่งงานของลูกค้าด้วย']);

    // Sent again: the rules are accepted again and the approval starts over.
    $this->actingAs($this->tech)->post("/room-access/requests/{$request->ulid}/submit", [])->assertSessionHasErrors('accept');
    $this->actingAs($this->tech)->post("/room-access/requests/{$request->ulid}/submit", ['accept' => true, 'version_id' => $this->v1->id])->assertSessionHasNoErrors();
    expect($request->fresh()->only(['status', 'round']))->toBe(['status' => 'pending', 'round' => 2])
        ->and(RoomRuleAcceptance::where('request_id', $request->id)->count())->toBe(2);

    ($this->decide)($request, 'reject', $this->desk)->assertSessionHasErrors('note');
    ($this->decide)($request, 'reject', $this->desk, 'ช่วงนั้นลูกค้าปิดปรับปรุง')->assertSessionHasNoErrors();
    expect($request->fresh()->only(['status', 'decision_note']))->toBe(['status' => 'rejected', 'decision_note' => 'ช่วงนั้นลูกค้าปิดปรับปรุง'])
        ->and($request->events()->pluck('action')->all())->toBe(['created', 'submitted', 'info_requested', 'submitted', 'rejected']);
});

it('tells the approvers and the requester where the company set its alerts', function () {
    Queue::fake();
    app(SaveAlertSettings::class)->handle($this->tenant, [
        'events' => ['room_access_requested', 'room_access_approved'],
        'line' => ['enabled' => true, 'to' => 'Cgroup', 'token' => 'line-token'],
        'telegram' => ['enabled' => false, 'chat_id' => '', 'token' => ''],
        'mail' => ['enabled' => false, 'recipients' => []],
    ]);

    $request = ($this->pending)();
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'room_access_requested'
        && str_contains($job->title, $request->request_no) && str_contains($job->body, 'DC Room 1') && str_contains($job->body, 'Acme')
        && str_contains($job->body, 'Tech One'));

    ($this->decide)($request, 'approve', $this->desk);
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'room_access_approved' && str_contains($job->body, 'Desk One'));
});
