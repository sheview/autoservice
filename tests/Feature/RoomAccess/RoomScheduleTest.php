<?php

use App\Modules\Platform\Actions\SaveAlertSettings;
use App\Modules\Platform\Jobs\DeliverAlert;
use App\Modules\RoomAccess\Actions\PublishRoomRules;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessVisit;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\Service\Models\Holiday;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Step 6: standing requests (approved once, used on chosen weekdays within a period), the rooms'
 * calendar with clashes, freeze periods and holidays, reminders (starting soon, still inside,
 * approval waiting too long), and the report of who went in and out (page, Excel, print).
 */

beforeEach(function () {
    $this->travelTo('2026-11-05 07:00'); // a Thursday
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->room = ServerRoom::create(['customer_id' => createCustomer(['name' => 'Acme'])->id, 'name' => 'DC Room 1']);
    $this->v1 = app(PublishRoomRules::class)->handle($this->room, ['summary' => ['ห้ามนำอาหารเข้า'], 'effective_on' => '2026-11-01'], null, $this->admin);

    $this->send = function (array $data, $user = null) {
        $this->actingAs($user ?? $this->tech)->post('/room-access/requests', [
            'server_room_id' => $this->room->id, 'purpose' => 'PM ประจำสัปดาห์', 'people' => [['name' => 'Tech One', 'company' => 'Our Co']],
            'submit' => true, 'accept' => true, 'version_id' => $this->v1->id, ...$data,
        ]);

        return RoomAccessRequest::latest('id')->first();
    };
    $this->approve = function (RoomAccessRequest $request) {
        $this->actingAs($this->desk)->post("/room-access/requests/{$request->ulid}/decide", ['decision' => 'approve'])->assertSessionHasNoErrors();

        return $request->fresh();
    };
    // Every Monday and Thursday 09:00-12:00 for November.
    $this->weekly = ['planned_start' => '2026-11-05T09:00', 'planned_end' => '2026-11-30T12:00',
        'recurrence' => ['weekdays' => [1, 4], 'start_time' => '09:00', 'end_time' => '12:00']];
    $this->post = fn (RoomAccessRequest $request, string $what, $user = null) => $this->actingAs($user ?? $this->tech)
        ->post("/room-access/requests/{$request->ulid}/{$what}");
});

it('lets a standing request in on its days and hours only, one visit each time, until its period ends', function () {
    $request = ($this->approve)(($this->send)($this->weekly));
    expect($request->recurrence)->toEqual(['weekdays' => [1, 4], 'start_time' => '09:00', 'end_time' => '12:00'])
        ->and($request->status)->toBe('approved');

    $this->travelTo('2026-11-05 08:10');
    ($this->post)($request, 'enter')->assertSessionHasNoErrors();
    $this->travelTo('2026-11-05 11:30');
    ($this->post)($request, 'exit')->assertSessionHasNoErrors();
    // Still usable on the next day of the schedule.
    expect($request->fresh()->status)->toBe('approved');

    $this->travelTo('2026-11-06 10:00'); // Friday: not one of its days
    ($this->post)($request, 'enter')->assertSessionHasErrors(['visit' => 'คำขอประจำนี้เข้าได้เฉพาะ จ. พฤ. 09:00-12:00 (บันทึกเข้าก่อนเวลาได้ไม่เกิน 1 ชั่วโมง)']);
    $this->travelTo('2026-11-09 13:00'); // Monday, after its hours
    ($this->post)($request, 'enter')->assertSessionHasErrors('visit');
    $this->travelTo('2026-11-09 09:15');
    ($this->post)($request, 'enter')->assertSessionHasNoErrors();
    $this->travelTo('2026-11-09 12:30');
    $this->actingAs($this->tech)->get("/room-access/requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('request.overstaying', true)->where('request.schedule', 'จ. พฤ. 09:00-12:00')->count('request.visits', 2));
    ($this->post)($request, 'exit')->assertSessionHasNoErrors();
    expect($request->events()->where('action', 'exited')->reorder()->latest('id')->value('note'))->toContain('30 นาที');

    // The period ends: used, so it ends as exited (and the requester sums up the work).
    $this->travelTo('2026-12-01 08:00');
    $this->artisan('room-access:mark-overdue')->assertSuccessful();
    expect($request->fresh()->status)->toBe('exited')
        ->and($request->events()->reorder()->latest('id')->value('action'))->toBe('period_ended')
        ->and(RoomAccessVisit::where('request_id', $request->id)->count())->toBe(2);
    ($this->post)($request, 'finish')->assertSessionHasErrors('work_summary');
});

it('ends a standing request nobody used as overdue', function () {
    $request = ($this->approve)(($this->send)($this->weekly));

    $this->travelTo('2026-12-01 08:00');
    $this->artisan('room-access:mark-overdue')->assertSuccessful();

    expect($request->fresh()->status)->toBe('overdue');
});

it('checks the schedule of a standing request when it is saved and sent', function () {
    // Only Saturdays, within a Monday-Friday span: no day to go in.
    ($this->send)(['planned_start' => '2026-11-09T09:00', 'planned_end' => '2026-11-13T12:00',
        'recurrence' => ['weekdays' => [6], 'start_time' => '09:00', 'end_time' => '12:00']]);
    expect(session('errors')->get('recurrence.weekdays'))->not->toBeEmpty();

    ($this->send)(['planned_start' => '2026-11-09T09:00', 'planned_end' => '2028-11-13T12:00',
        'recurrence' => ['weekdays' => [1], 'start_time' => '09:00', 'end_time' => '12:00']]);
    expect(session('errors')->get('planned_end'))->not->toBeEmpty();

    ($this->send)(['planned_start' => '2026-11-09T09:00', 'planned_end' => '2026-11-13T12:00',
        'recurrence' => ['weekdays' => [1], 'start_time' => '12:00', 'end_time' => '09:00']]);
    expect(session('errors')->get('recurrence.end_time'))->not->toBeEmpty();

    // A freeze on a Tuesday does not touch a Monday/Thursday schedule; one on a Thursday does.
    $this->room->update(['freeze_periods' => [['from' => '2026-11-10 00:00', 'to' => '2026-11-10 23:59', 'reason' => 'Change window']]]);
    expect(($this->send)($this->weekly)->status)->toBe('pending');
    $this->room->update(['freeze_periods' => [['from' => '2026-11-19 00:00', 'to' => '2026-11-19 23:59', 'reason' => 'Audit']]]);
    expect(($this->send)($this->weekly)->status)->toBe('draft');
});

it('warns about other bookings, freezes and holidays, hiding requests the user may not open', function () {
    $other = ($this->send)(['planned_start' => '2026-11-12T10:00', 'planned_end' => '2026-11-12T11:00'], $this->desk);
    Holiday::create(['date' => '2026-11-16', 'name' => 'วันหยุดบริษัท']);
    $this->room->update(['freeze_periods' => [['from' => '2026-11-26 08:00', 'to' => '2026-11-26 18:00', 'reason' => 'Audit']]]);
    $query = ['server_room_id' => $this->room->id, ...$this->weekly];

    // The technician sees only their own requests: the other one shows only that the room is taken.
    $this->actingAs($this->tech)->getJson(route('room-access.requests.clashes', $query))->assertOk()
        ->assertJsonPath('requests.0.request_no', null)->assertJsonPath('requests.0.status', 'pending')
        ->assertJsonPath('holidays.0', ['date' => '2026-11-16', 'name' => 'วันหยุดบริษัท'])
        ->assertJsonPath('freezes.0.reason', 'Audit');
    $this->actingAs($this->desk)->getJson(route('room-access.requests.clashes', $query))
        ->assertJsonPath('requests.0.request_no', $other->request_no)->assertJsonPath('requests.0.requester_name', 'Desk One');

    // A time on none of the schedule's days does not clash.
    $this->actingAs($this->desk)->getJson(route('room-access.requests.clashes', [...$query, 'recurrence' => ['weekdays' => [2], 'start_time' => '09:00', 'end_time' => '12:00']]))
        ->assertJsonCount(0, 'requests')->assertJsonCount(0, 'holidays')->assertJsonCount(0, 'freezes');
    // The request page warns too.
    $this->actingAs($this->desk)->get("/room-access/requests/{$other->ulid}")->assertInertia(fn (Assert $page) => $page->where('clashes.requests', []));
});

it('shows the month: each day of a standing request, freezes and holidays, others masked', function () {
    ($this->approve)(($this->send)($this->weekly));
    ($this->send)(['planned_start' => '2026-11-12T10:00', 'planned_end' => '2026-11-12T11:00'], $this->desk);
    Holiday::create(['date' => '2026-11-16', 'name' => 'วันหยุดบริษัท']);
    $this->room->update(['freeze_periods' => [['from' => '2026-11-26 08:00', 'to' => '2026-11-26 18:00', 'reason' => 'Audit']]]);

    // November 2026: Mondays 9, 16, 23, 30 and Thursdays 5, 12, 19, 26 (the freeze already set after approval).
    $this->actingAs($this->tech)->get('/room-access/calendar?month=2026-11')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('RoomAccess/Calendar')
        ->has('entries', 9)
        ->where('entries.0.requester_name', 'Tech One')->where('entries.0.recurring', true)
        ->where('entries', fn ($entries) => collect($entries)->where('requester_name', null)->count() === 1)
        ->where('holidays', ['2026-11-16' => 'วันหยุดบริษัท'])
        ->where('freezes.0.reason', 'Audit'));
    $this->actingAs($this->tech)->get('/room-access/calendar?month=2026-12')->assertInertia(fn (Assert $page) => $page->has('entries', 0));
    $this->actingAs(userWithRole('customer_it', ['customer_id' => createCustomer()->id]))->get('/room-access/calendar')->assertForbidden();
});

it('reminds once: starting soon, still inside after the end, approval waiting too long', function () {
    Queue::fake();
    app(SaveAlertSettings::class)->handle($this->tenant, [
        'events' => ['room_access_starting_soon', 'room_access_overstay', 'room_access_approval_overdue'],
        'line' => ['enabled' => true, 'to' => 'Cgroup', 'token' => 'line-token'],
        'telegram' => ['enabled' => false, 'chat_id' => '', 'token' => ''],
        'mail' => ['enabled' => false, 'recipients' => []],
    ]);
    $sent = fn (string $event) => Queue::pushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === $event)->count();

    $standing = ($this->approve)(($this->send)($this->weekly));
    $pending = ($this->send)(['planned_start' => '2026-11-20T10:00', 'planned_end' => '2026-11-20T11:00']);

    $this->travelTo('2026-11-05 08:20');
    $this->artisan('room-access:notify')->assertSuccessful();
    $this->artisan('room-access:notify')->assertSuccessful();
    expect($sent('room_access_starting_soon'))->toBe(1);

    // Inside past 12:00.
    ($this->post)($standing, 'enter')->assertSessionHasNoErrors();
    $this->travelTo('2026-11-05 12:20');
    $this->artisan('room-access:notify')->assertSuccessful();
    $this->artisan('room-access:notify')->assertSuccessful();
    expect($sent('room_access_overstay'))->toBe(1);
    ($this->post)($standing, 'exit')->assertSessionHasNoErrors();

    // The next Monday reminds again; the pending request is past 24 hours by then.
    $this->travelTo('2026-11-09 08:30');
    $this->artisan('room-access:notify')->assertSuccessful();
    $this->artisan('room-access:notify')->assertSuccessful();
    expect($sent('room_access_starting_soon'))->toBe(2)
        ->and($sent('room_access_approval_overdue'))->toBe(1)
        ->and($pending->fresh()->approval_alerted_at)->not->toBeNull();
    Queue::assertPushed(DeliverAlert::class, fn (DeliverAlert $job) => $job->event === 'room_access_approval_overdue' && str_contains($job->title, '24'));
});

it('reports who went in and out, within what the user may see, as a page, Excel and print', function () {
    $mine = ($this->approve)(($this->send)(['planned_start' => '2026-11-05T09:00', 'planned_end' => '2026-11-05T12:00']));
    $two = userWithRole('technician', ['name' => 'Tech Two']);
    $theirs = ($this->approve)(($this->send)(['planned_start' => '2026-11-05T09:00', 'planned_end' => '2026-11-05T10:00', 'people' => [['name' => 'คุณสมหญิง']]], $two));
    $this->travelTo('2026-11-05 09:00');
    ($this->post)($mine, 'enter')->assertSessionHasNoErrors();
    ($this->post)($theirs, 'enter', $two)->assertSessionHasNoErrors();
    $this->travelTo('2026-11-05 11:00');
    ($this->post)($mine, 'exit')->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("/room-access/requests/{$mine->ulid}/finish", ['work_summary' => 'ล้างแอร์ ตรวจ UPS'])->assertSessionHasNoErrors();

    $period = ['from' => '2026-11-01', 'to' => '2026-11-30'];
    $this->actingAs($this->desk)->get(route('room-access.report', $period))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('RoomAccess/Report')->has('rows.data', 2));
    $this->actingAs($this->tech)->get(route('room-access.report', $period))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)
        ->where('rows.data.0.request_no', $mine->request_no)->where('rows.data.0.minutes', 120)
        ->where('rows.data.0.work_summary', 'ล้างแอร์ ตรวจ UPS')->where('rows.data.0.people', ['Tech One (Our Co)'])
        ->where('rows.data.0.entered_by_name', 'Tech One'));
    $this->actingAs($this->desk)->get(route('room-access.report', [...$period, 'search' => 'สมหญิง']))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)->where('rows.data.0.exited_at', null));
    $this->actingAs($this->desk)->get(route('room-access.report', ['from' => '2026-12-01', 'to' => '2026-12-31']))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));

    Excel::fake();
    $this->actingAs($this->desk)->get(route('room-access.report.export', $period))->assertOk();
    Excel::assertDownloaded('room-visits-20261101-20261130.xlsx', fn ($sheet) => count($sheet->array()) === 2);
    $this->actingAs($this->tech)->get(route('room-access.report.print', $period))->assertOk()
        ->assertSee('ล้างแอร์ ตรวจ UPS')->assertSee('Tech One (Our Co)')->assertDontSee('คุณสมหญิง');
    $this->actingAs(userWithRole('customer_it', ['customer_id' => createCustomer()->id]))->get(route('room-access.report', $period))->assertForbidden();
});

it('keeps another company out of the calendar and the report', function () {
    $request = ($this->approve)(($this->send)(['planned_start' => '2026-11-05T09:00', 'planned_end' => '2026-11-05T12:00']));
    $this->travelTo('2026-11-05 09:00');
    ($this->post)($request, 'enter')->assertSessionHasNoErrors();

    $other = createTenant('other');
    $outsider = userWithRole('admin_company', [], $other);
    asTenant($other, function () use ($outsider) {
        $this->actingAs($outsider)->get('/room-access/calendar?month=2026-11')->assertInertia(fn (Assert $page) => $page->has('entries', 0)->has('rooms', 0));
        $this->actingAs($outsider)->get(route('room-access.report', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
        expect(RoomAccessVisit::count())->toBe(0);
    });
});
