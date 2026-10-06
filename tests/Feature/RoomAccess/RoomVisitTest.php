<?php

use App\Modules\Platform\Models\Activity;
use App\Modules\RoomAccess\Actions\PublishRoomRules;
use App\Modules\RoomAccess\Jobs\SendGuardLinkMail;
use App\Modules\RoomAccess\Models\RoomAccessPerson;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\RoomAccess\Models\RoomRuleAcceptance;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Models\ServerRoomManager;
use App\Modules\RoomAccess\Notifications\GuardLinkMail;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The visit: going in and out (the requester, a caretaker of the room, or a guard through the
 * request's guard link, which works for that request only until it expires or is revoked), the
 * work summary afterwards, requests nobody used becoming overdue, and ID numbers deleted after
 * the company's retention.
 */

beforeEach(function () {
    CompanyCodes::forget();
    config(['app.url' => 'http://localhost', 'tenancy.public_links' => 'path']);
    $this->travelTo('2026-11-05 09:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->room = ServerRoom::create([
        'customer_id' => createCustomer(['name' => 'Acme'])->id, 'name' => 'DC Room 1', 'requires_id_number' => true,
        'guard_link' => true, 'guard_contacts' => [['name' => 'รปภ. สมศักดิ์', 'email' => 'guard@acme.test']],
    ]);
    $this->v1 = app(PublishRoomRules::class)->handle($this->room, ['summary' => ['ห้ามนำอาหารเข้า'], 'effective_on' => '2026-11-01'], null, $this->admin);

    $this->approved = function () {
        $this->actingAs($this->tech)->post('/room-access/requests', [
            'server_room_id' => $this->room->id, 'planned_start' => '2026-11-05 10:00', 'planned_end' => '2026-11-05 12:00',
            'purpose' => 'PM', 'people' => [['name' => 'Tech One', 'id_number' => '1234567890123']],
            'items' => [['name' => 'Laptop', 'serial_number' => 'LT-1', 'direction' => 'in']],
            'submit' => true, 'accept' => true, 'version_id' => $this->v1->id,
        ])->assertSessionHasNoErrors();
        $request = RoomAccessRequest::latest('id')->first();
        $this->actingAs($this->desk)->post("/room-access/requests/{$request->ulid}/decide", ['decision' => 'approve'])->assertSessionHasNoErrors();

        return $request->fresh();
    };
    $this->post = fn (RoomAccessRequest $request, string $what, array $data = [], $user = null) => $this->actingAs($user ?? $this->tech)
        ->post("/room-access/requests/{$request->ulid}/{$what}", $data);
});

it('records going in within the time and coming out, then the work summary', function () {
    $request = ($this->approved)();

    $this->travelTo('2026-11-05 08:30');
    ($this->post)($request, 'enter')->assertSessionHasErrors('visit'); // more than an hour early
    $this->travelTo('2026-11-05 09:30');
    ($this->post)($request, 'enter', [], userWithRole('technician'))->assertForbidden();
    ($this->post)($request, 'enter')->assertSessionHasNoErrors();
    expect($request->fresh()->only(['status', 'entered_by_name']))->toBe(['status' => 'inside', 'entered_by_name' => 'Tech One']);

    $this->travelTo('2026-11-05 12:20');
    $this->actingAs($this->tech)->get("/room-access/requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page->where('request.overstaying', true));
    ($this->post)($request, 'exit')->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('exited')
        ->and($request->events()->where('action', 'exited')->value('note'))->toContain('20 นาที');

    ($this->post)($request, 'finish', ['work_summary' => 'เปลี่ยน HDD แล้ว'])->assertSessionHasErrors('items_confirmed');
    ($this->post)($request, 'finish', ['work_summary' => 'x', 'items_confirmed' => true], $this->desk)->assertSessionHasErrors('work_summary');
    ($this->post)($request, 'finish', ['work_summary' => 'เปลี่ยน HDD แล้ว ทดสอบผ่าน', 'items_confirmed' => true])->assertSessionHasNoErrors();
    expect($request->fresh()->only(['work_summary', 'items_confirmed_by_name']))->toBe(['work_summary' => 'เปลี่ยน HDD แล้ว ทดสอบผ่าน', 'items_confirmed_by_name' => 'Tech One'])
        ->and($request->events()->pluck('action')->all())->toBe(['created', 'submitted', 'approved', 'entered', 'exited', 'finished']);
});

it('lets a caretaker of the room record for the team, and asks the rules again at the door when the room says so', function () {
    $this->room->update(['accept_on_enter' => true]);
    $request = ($this->approved)();
    ServerRoomManager::create(['server_room_id' => $this->room->id, 'user_id' => $this->desk->id]);

    ($this->post)($request, 'enter', [], $this->desk)->assertSessionHasErrors('accept');
    ($this->post)($request, 'enter', ['accept' => true], $this->desk)->assertSessionHasNoErrors();

    expect($request->fresh()->entered_by_name)->toBe('Desk One')
        ->and(RoomRuleAcceptance::where('request_id', $request->id)->where('context', 'enter')->sole()->only(['version', 'accepted_by_name']))
        ->toBe(['version' => 1, 'accepted_by_name' => 'Desk One']);
});

it('sends the room guards their link, which records going in and out for that request only', function () {
    Queue::fake();
    $request = ($this->approved)();
    Queue::assertPushed(SendGuardLinkMail::class);
    $guard = RoomAccessToken::where('request_id', $request->id)->where('purpose', 'guard')->sole();
    $permit = RoomAccessToken::where('request_id', $request->id)->where('purpose', 'permit')->sole();

    // The mail itself: the link, names only.
    Notification::fake();
    (new SendGuardLinkMail($guard->id))->handle(app(TenantContext::class));
    Notification::assertSentOnDemand(GuardLinkMail::class, fn ($mail, $channels, $notifiable) => $notifiable->routes['mail'] === 'guard@acme.test'
        && str_contains($mail->link, "/t/001/room-guard/{$guard->token}"));

    auth()->logout();
    $this->get("/t/001/room-guard/{$guard->token}")->assertInertia(fn (Assert $page) => $page
        ->component('RoomAccess/Guard')->where('page.usable', true)->where('page.permit.people.0.name', 'Tech One')
        ->where('page.permit.people.0.id_number', null));
    // The permit link is not a guard link.
    $this->get("/t/001/room-guard/{$permit->token}")->assertInertia(fn (Assert $page) => $page->where('page', null));
    $this->post("/t/001/room-guard/{$permit->token}/enter", ['guard_name' => 'X'])->assertNotFound();

    $this->post("/t/001/room-guard/{$guard->token}/enter", [])->assertSessionHasErrors('guard_name');
    $this->post("/t/001/room-guard/{$guard->token}/enter", ['guard_name' => 'สมศักดิ์'])->assertSessionHasNoErrors();
    expect($request->fresh()->only(['status', 'entered_by_name']))->toBe(['status' => 'inside', 'entered_by_name' => 'สมศักดิ์ (รปภ./ผู้ดูแลห้อง ผ่านลิงก์)']);
    $this->post("/t/001/room-guard/{$guard->token}/exit", ['guard_name' => 'สมศักดิ์'])->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe('exited');

    // Another company's code: not found; an expired link: no more recording.
    $other = createTenant('other');
    CompanyCodes::forget();
    $this->get('/t/'.$other->fresh()->company_code."/room-guard/{$guard->token}")->assertInertia(fn (Assert $page) => $page->where('page', null));
    $this->travelTo('2026-11-06 01:00');
    $this->get("/t/001/room-guard/{$guard->token}")->assertInertia(fn (Assert $page) => $page->where('page.usable', false));
});

it('stops a guard link that was renewed or whose request was cancelled', function () {
    Queue::fake();
    $request = ($this->approved)();
    $old = RoomAccessToken::where('request_id', $request->id)->where('purpose', 'guard')->value('token');

    ($this->post)($request, 'guard-link')->assertSessionHasNoErrors();
    auth()->logout();
    $this->post("/t/001/room-guard/{$old}/enter", ['guard_name' => 'X'])->assertNotFound();

    $new = RoomAccessToken::where('request_id', $request->id)->where('purpose', 'guard')->whereNull('revoked_at')->value('token');
    ($this->post)($request, 'cancel', ['reason' => 'เลื่อน']);
    auth()->logout();
    $this->post("/t/001/room-guard/{$new}/enter", ['guard_name' => 'X'])->assertNotFound();
});

it('marks approved requests nobody used as overdue, and their links stop', function () {
    Queue::fake();
    $request = ($this->approved)();

    $this->travelTo('2026-11-05 12:01');
    $this->artisan('room-access:mark-overdue')->assertSuccessful();

    expect($request->fresh()->status)->toBe('overdue')
        ->and(RoomAccessToken::where('request_id', $request->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('deletes ID numbers after the company keeps them, only of requests that ended', function () {
    Queue::fake();
    $old = ($this->approved)();
    ($this->post)($old, 'enter');
    $this->travelTo('2026-11-05 11:00');
    ($this->post)($old, 'exit');
    $recent = ($this->approved)();

    $this->travelTo('2027-02-04 04:00'); // 91 days later
    $this->artisan('room-access:purge-ids')->assertSuccessful();

    expect(RoomAccessPerson::where('request_id', $old->id)->value('id_number'))->toBeNull()
        ->and($old->fresh()->id_numbers_purged_at)->not->toBeNull()
        // Not ended (approved, never used, not marked yet): kept.
        ->and(RoomAccessPerson::where('request_id', $recent->id)->value('id_number'))->toBe('1234567890123')
        ->and(Activity::where('event', 'room_access_ids_purged')->count())->toBe(1);

    $this->actingAs($this->admin)->get("/room-access/requests/{$old->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('request.id_numbers_purged', true)->where('request.people.0.id_number', null)->where('can.viewIds', false));
});
