<?php

use App\Modules\Platform\Models\Activity;
use App\Modules\RoomAccess\Actions\PublishRoomRules;
use App\Modules\RoomAccess\Models\RoomAccessPerson;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomRuleAcceptance;
use App\Modules\RoomAccess\Models\RoomVisitor;
use App\Modules\RoomAccess\Models\ServerRoom;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Asking to enter a server room: the form, the accept popup (the chosen room's rules only), and
 * sending, which needs the rules accepted (also when called directly), keeps the evidence with a
 * copy of the text, and is refused for rooms without rules (as set), frozen times and missing ID
 * numbers. ID numbers are masked and shown in full only to whoever may, each time logged.
 */

beforeEach(function () {
    $this->travelTo('2026-11-02 09:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->acme = createCustomer(['name' => 'Acme']);
    $this->room = ServerRoom::create(['customer_id' => $this->acme->id, 'name' => 'DC Room 1']);
    $this->rules = fn (ServerRoom $room, array $lines = ['ห้ามนำอาหารเข้า', 'ต้องมีเจ้าหน้าที่ลูกค้าอยู่ด้วย']) => app(PublishRoomRules::class)
        ->handle($room, ['summary' => $lines, 'effective_on' => '2026-11-01'], null, $this->admin);
    $this->v1 = ($this->rules)($this->room);

    $this->payload = fn (array $data = []) => $data + [
        'server_room_id' => $this->room->id, 'planned_start' => '2026-11-05 10:00', 'planned_end' => '2026-11-05 12:00',
        'purpose' => 'เปลี่ยน HDD ของ server', 'people' => [['name' => 'Tech One', 'company' => 'ITSol', 'phone' => '0811111111']],
        'items' => [['name' => 'HDD 2TB', 'serial_number' => 'HDD-1', 'quantity' => 1, 'direction' => 'in']],
    ];
    // Saves a draft and returns it.
    $this->draft = function (array $data = [], $user = null) {
        $this->actingAs($user ?? $this->tech)->post('/room-access/requests', ($this->payload)($data))->assertSessionHasNoErrors();

        return RoomAccessRequest::latest('id')->first();
    };
    $this->send = fn (RoomAccessRequest $request, array $answer, $user = null) => $this->actingAs($user ?? $this->tech)
        ->post("/room-access/requests/{$request->ulid}/submit", $answer);
});

it('saves a draft with people and equipment, numbered for the year', function () {
    $request = ($this->draft)();

    expect($request->only(['request_no', 'status', 'requester_id', 'customer_id']))->toBe([
        'request_no' => 'SR-2569-00001', 'status' => 'draft', 'requester_id' => $this->tech->id, 'customer_id' => $this->acme->id,
    ])
        ->and($request->people()->pluck('name')->all())->toBe(['Tech One'])
        ->and($request->items()->first()->only(['name', 'serial_number', 'direction']))->toBe(['name' => 'HDD 2TB', 'serial_number' => 'HDD-1', 'direction' => 'in']);
});

it('sends nothing without the rules accepted, from the page or straight to the server', function () {
    $request = ($this->draft)();

    ($this->send)($request, [])->assertSessionHasErrors('accept');
    ($this->send)($request, ['accept' => false, 'version_id' => $this->v1->id])->assertSessionHasErrors('accept');
    // Saving with "submit" but no acceptance keeps a draft.
    $this->actingAs($this->tech)->put("/room-access/requests/{$request->ulid}", ($this->payload)(['submit' => true]))
        ->assertRedirect("/room-access/requests/{$request->ulid}/edit")->assertSessionHasErrors('accept');

    expect($request->fresh()->status)->toBe('draft')->and(RoomRuleAcceptance::count())->toBe(0);
});

it('keeps the version accepted and a copy of its text, which a later version does not change', function () {
    $request = ($this->draft)();
    ($this->send)($request, ['accept' => true, 'version_id' => $this->v1->id])->assertSessionHasNoErrors();

    $acceptance = RoomRuleAcceptance::sole();
    expect($request->fresh()->only(['status', 'rule_version_id']))->toBe(['status' => 'pending', 'rule_version_id' => $this->v1->id])
        ->and($acceptance->only(['version', 'context', 'user_id', 'accepted_by_name', 'on_behalf_of_team', 'ip']))->toBe([
            'version' => 1, 'context' => 'submit', 'user_id' => $this->tech->id, 'accepted_by_name' => 'Tech One', 'on_behalf_of_team' => true, 'ip' => '127.0.0.1',
        ])
        ->and($acceptance->snapshot['summary'])->toBe(['ห้ามนำอาหารเข้า', 'ต้องมีเจ้าหน้าที่ลูกค้าอยู่ด้วย'])
        ->and($acceptance->snapshot['customer'])->toBe('Acme')
        ->and($acceptance->snapshot['company_terms'])->toHaveCount(3);

    // The customer changes the rules: the request still shows what was accepted.
    ($this->rules)($this->room, ['กฎใหม่ทั้งหมด']);
    $this->actingAs($this->tech)->get("/room-access/requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('request.acceptances.0.version', 1)
        ->where('request.acceptances.0.snapshot.summary', ['ห้ามนำอาหารเข้า', 'ต้องมีเจ้าหน้าที่ลูกค้าอยู่ด้วย'])
        ->where('request.events.1.action', 'submitted'));
});

it('asks again when the rules changed after the popup opened', function () {
    $request = ($this->draft)();
    $v2 = ($this->rules)($this->room, ['กฎใหม่']);

    ($this->send)($request, ['accept' => true, 'version_id' => $this->v1->id])->assertSessionHasErrors('accept');
    ($this->send)($request, ['accept' => true, 'version_id' => $v2->id])->assertSessionHasNoErrors();
    expect(RoomRuleAcceptance::sole()->version)->toBe(2);
});

it('shows the popup the chosen room only, and only rooms of the company', function () {
    $beta = createCustomer(['name' => 'Beta']);
    $betaRoom = ServerRoom::create(['customer_id' => $beta->id, 'name' => 'Beta Room']);
    ($this->rules)($betaRoom, ['กฎลับของ Beta']);

    $this->actingAs($this->tech)->getJson("/room-access/requests/rooms/{$this->room->ulid}/rules")->assertOk()
        ->assertJsonPath('customer', 'Acme')->assertJsonPath('version', 1)
        ->assertJsonPath('summary', ['ห้ามนำอาหารเข้า', 'ต้องมีเจ้าหน้าที่ลูกค้าอยู่ด้วย'])
        ->assertJsonMissing(['กฎลับของ Beta']);

    $other = createTenant('other');
    $otherRoom = asTenant($other, fn () => ServerRoom::create(['customer_id' => createCustomer()->id, 'name' => 'Theirs']));
    $this->actingAs($this->tech)->getJson("/room-access/requests/rooms/{$otherRoom->ulid}/rules")->assertNotFound();
    $this->actingAs($this->tech)->post('/room-access/requests', ($this->payload)(['server_room_id' => $otherRoom->id]))->assertSessionHasErrors('server_room_id');
});

it('blocks a room without rules, or shows the company terms with a warning, as the room says', function () {
    $bare = ServerRoom::create(['customer_id' => $this->acme->id, 'name' => 'New room']);
    $request = ($this->draft)(['server_room_id' => $bare->id]);

    $this->actingAs($this->tech)->getJson("/room-access/requests/rooms/{$bare->ulid}/rules")->assertJsonPath('blocked', true);
    ($this->send)($request, ['accept' => true, 'version_id' => null])->assertSessionHasErrors('rules');

    $bare->update(['missing_rules' => 'company_terms']);
    $this->actingAs($this->tech)->getJson("/room-access/requests/rooms/{$bare->ulid}/rules")->assertJsonPath('blocked', false)->assertJsonPath('missing', true);
    ($this->send)($request, ['accept' => true, 'version_id' => null])->assertSessionHasNoErrors();
    expect(RoomRuleAcceptance::sole()->only(['version', 'rule_version_id']))->toBe(['version' => null, 'rule_version_id' => null])
        ->and(RoomRuleAcceptance::sole()->snapshot['missing'])->toBeTrue();
});

it('asks once per version when the room says so', function () {
    $this->room->update(['accept_mode' => 'once_per_version']);
    ($this->send)(($this->draft)(), ['accept' => true, 'version_id' => $this->v1->id])->assertSessionHasNoErrors();

    $second = ($this->draft)(['planned_start' => '2026-11-06 10:00', 'planned_end' => '2026-11-06 11:00']);
    $this->actingAs($this->tech)->getJson("/room-access/requests/rooms/{$this->room->ulid}/rules")->assertJsonPath('accepted_before', fn ($at) => $at !== null);
    ($this->send)($second, [])->assertSessionHasNoErrors();

    expect($second->fresh()->status)->toBe('pending')->and(RoomRuleAcceptance::count())->toBe(1)
        ->and($second->events()->where('action', 'submitted')->value('note'))->toContain('เวอร์ชัน 1');
});

it('refuses frozen times, past times and missing ID numbers', function () {
    $this->room->update(['freeze_periods' => [['from' => '2026-11-05 00:00', 'to' => '2026-11-05 23:59', 'reason' => 'ย้ายระบบ']]]);
    $frozen = ($this->draft)();
    ($this->send)($frozen, ['accept' => true, 'version_id' => $this->v1->id])->assertSessionHasErrors('planned_start');

    $this->room->update(['freeze_periods' => [], 'requires_id_number' => true]);
    ($this->send)($frozen, ['accept' => true, 'version_id' => $this->v1->id])->assertSessionHasErrors('people');

    $this->travelTo('2026-11-06 09:00');
    ($this->send)($frozen, ['accept' => true, 'version_id' => $this->v1->id])->assertSessionHasErrors('planned_end');
    expect($frozen->fresh()->status)->toBe('draft');
});

it('keeps ID numbers encrypted and masked, shown in full only to whoever may, each time logged', function () {
    $this->room->update(['requires_id_number' => true]);
    $request = ($this->draft)(['people' => [['name' => 'Somchai', 'id_number' => '1-2345-67890-12-3']]]);
    $person = RoomAccessPerson::sole();

    expect($person->id_number)->toBe('1234567890123')
        ->and(DB::table('room_access_people')->value('id_number'))->not->toContain('1234567890123');

    $this->actingAs($this->tech)->get("/room-access/requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('request.people.0.id_number', '1-23XX-XXXXX-XX-3')->where('can.viewIds', false));
    $this->actingAs($this->tech)->postJson("/room-access/requests/{$request->ulid}/people/{$person->id}/id")->assertForbidden();

    $this->actingAs($this->admin)->postJson("/room-access/requests/{$request->ulid}/people/{$person->id}/id")->assertOk()
        ->assertJsonPath('id_number', '1234567890123');
    expect(Activity::where('event', 'room_access_id_viewed')->sole()->causer_id)->toBe($this->admin->id);

    // Editing the draft without retyping keeps the number.
    $this->actingAs($this->tech)->put("/room-access/requests/{$request->ulid}", ($this->payload)([
        'people' => [['id' => $person->id, 'name' => 'Somchai', 'id_number' => '']],
    ]))->assertSessionHasNoErrors();
    expect(RoomAccessPerson::sole()->id_number)->toBe('1234567890123');
});

it('offers the requester the people they took in before (never their ID numbers), and copies an earlier request', function () {
    $this->room->update(['requires_id_number' => true]);
    $request = ($this->draft)(['people' => [['name' => 'Somchai', 'company' => 'ITSol', 'phone' => '0899', 'id_number' => '1234567890123']]]);
    ($this->send)($request, ['accept' => true, 'version_id' => $this->v1->id])->assertSessionHasNoErrors();

    expect(RoomVisitor::sole()->only(['owner_id', 'name', 'company', 'phone']))->toBe(['owner_id' => $this->tech->id, 'name' => 'Somchai', 'company' => 'ITSol', 'phone' => '0899']);
    $this->actingAs($this->tech)->get('/room-access/requests/create')->assertInertia(fn (Assert $page) => $page
        ->where('visitors', [['name' => 'Somchai', 'company' => 'ITSol', 'phone' => '0899']]));
    // Someone else does not see them.
    $this->actingAs(userWithRole('technician'))->get('/room-access/requests/create')->assertInertia(fn (Assert $page) => $page->where('visitors', []));

    $this->actingAs($this->tech)->get("/room-access/requests/create?copy={$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('copy.server_room_id', $this->room->id)->where('copy.people.0.name', 'Somchai')->where('copy.people.0.id_number', '')
        ->where('copy.people.0.id_number_masked', null)->where('copy.planned_start', null));
});

it('shows a technician only their own requests, and keeps companies apart', function () {
    $mine = ($this->draft)();
    $theirs = ($this->draft)([], userWithRole('technician'));

    $this->actingAs($this->tech)->get('/room-access/requests?status=all')->assertInertia(fn (Assert $page) => $page
        ->where('requests.total', 1)->where('requests.data.0.request_no', $mine->request_no));
    $this->actingAs($this->tech)->get("/room-access/requests/{$theirs->ulid}")->assertForbidden();
    $this->actingAs($this->admin)->get('/room-access/requests?status=all')->assertInertia(fn (Assert $page) => $page->where('requests.total', 2));

    $other = createTenant('other');
    $this->actingAs(userWithRole('admin_company', [], $other))->get("/room-access/requests/{$mine->ulid}")->assertNotFound();
});

it('lets the requester cancel before going in, with a reason', function () {
    $request = ($this->draft)();
    ($this->send)($request, ['accept' => true, 'version_id' => $this->v1->id]);

    $this->actingAs($this->admin)->post("/room-access/requests/{$request->ulid}/cancel")->assertForbidden();
    $this->actingAs($this->tech)->post("/room-access/requests/{$request->ulid}/cancel", ['reason' => 'ลูกค้าเลื่อน'])->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe('cancelled')
        ->and($request->events()->reorder()->latest('id')->first()->only(['action', 'from_status', 'to_status', 'note']))
        ->toBe(['action' => 'cancelled', 'from_status' => 'pending', 'to_status' => 'cancelled', 'note' => 'ลูกค้าเลื่อน']);
});

it('keeps planned times after 2038 (a standing request can run as long as its contract)', function () {
    $request = ($this->draft)(['planned_start' => '2040-03-01 09:00', 'planned_end' => '2040-03-01 11:00']);

    expect($request->planned_start->format('Y-m-d H:i'))->toBe('2040-03-01 09:00')
        ->and($request->planned_end->format('Y-m-d H:i'))->toBe('2040-03-01 11:00');
});
