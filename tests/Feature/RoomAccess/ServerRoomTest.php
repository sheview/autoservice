<?php

use App\Modules\Contract\Models\CustomerSite;
use App\Modules\Platform\Models\Activity;
use App\Modules\Platform\Support\Modules;
use App\Modules\RoomAccess\Models\RoomApprovalStep;
use App\Modules\RoomAccess\Models\RoomRuleVersion;
use App\Modules\RoomAccess\Models\ServerRoom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/*
 * Setting up server rooms: each room of a customer (and site), who approves and looks after it,
 * how its rules are accepted, freeze periods, and the customer's rules as versions that are
 * never changed. Only for whoever holds room-access.manage, and only within the company.
 */

beforeEach(function () {
    Storage::fake('public');
    $this->travelTo('2026-10-31 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->acme = createCustomer(['name' => 'Acme']);
    $this->site = CustomerSite::create(['customer_id' => $this->acme->id, 'name' => 'Bangna DC']);
    $this->room = fn (array $data = []) => $this->actingAs($this->admin)->post('/room-access/rooms', $data + [
        'customer_id' => $this->acme->id, 'site_id' => $this->site->id, 'name' => 'Server Room 1', 'location' => 'ชั้น 3',
        'missing_rules' => 'block', 'accept_mode' => 'every_request',
    ]);
    $this->publish = fn (ServerRoom $room, array $data = []) => $this->actingAs($this->admin)->post("/room-access/rooms/{$room->ulid}/rules", $data + [
        'summary' => ['ห้ามนำอาหารเข้า', 'ต้องมีเจ้าหน้าที่ลูกค้าอยู่ด้วย'], 'effective_on' => '2026-10-31',
    ]);
});

it('sets up a room of a customer site with its approver, caretakers, guards and freeze periods', function () {
    ($this->room)([
        'requires_id_number' => true, 'accept_mode' => 'once_per_version', 'accept_on_enter' => true,
        'approver_user_id' => $this->desk->id, 'manager_ids' => [$this->desk->id],
        'guard_contacts' => [['name' => 'รปภ. สมศักดิ์', 'phone' => '0811111111', 'email' => 'guard@acme.test']],
        'freeze_periods' => [['from' => '2026-12-30 00:00', 'to' => '2027-01-02 23:59', 'reason' => 'ปีใหม่']],
    ])->assertSessionHasNoErrors();

    $room = ServerRoom::sole();
    expect($room->only(['name', 'customer_id', 'site_id', 'requires_id_number', 'accept_mode', 'accept_on_enter', 'missing_rules']))->toBe([
        'name' => 'Server Room 1', 'customer_id' => $this->acme->id, 'site_id' => $this->site->id, 'requires_id_number' => true,
        'accept_mode' => 'once_per_version', 'accept_on_enter' => true, 'missing_rules' => 'block',
    ])
        ->and($room->guard_contacts[0]['name'])->toBe('รปภ. สมศักดิ์')
        ->and($room->freeze_periods[0]['reason'])->toBe('ปีใหม่')
        ->and(RoomApprovalStep::sole()->only(['position', 'side', 'approver_user_id']))->toBe(['position' => 1, 'side' => 'company', 'approver_user_id' => $this->desk->id])
        ->and($room->managers()->pluck('user_id')->all())->toBe([$this->desk->id]);

    $this->actingAs($this->admin)->get("/room-access/rooms/{$room->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('RoomAccess/Rooms/Show')
        ->where('room.customer', 'Acme')->where('room.site', 'Bangna DC')
        ->where('room.approvers.0.name', 'Desk One')->where('room.managers', ['Desk One']));
});

it('refuses a second room of the same name for the customer, and a site of another customer', function () {
    ($this->room)()->assertSessionHasNoErrors();
    ($this->room)(['name' => 'server room 1', 'site_id' => null])->assertSessionHasErrors('name');

    $beta = createCustomer(['name' => 'Beta']);
    ($this->room)(['customer_id' => $beta->id, 'name' => 'Main'])->assertSessionHasErrors('site_id');
    // A technician cannot be named approver (no room-access.approve).
    ($this->room)(['name' => 'Other', 'approver_user_id' => userWithRole('technician')->id])->assertSessionHasErrors('approver_user_id');
});

it('keeps every version of the rules, never changed, and knows which one is in effect', function () {
    ($this->room)()->assertSessionHasNoErrors();
    $room = ServerRoom::sole();

    ($this->publish)($room, ['summary' => ['', ' ']])->assertSessionHasErrors('summary');
    ($this->publish)($room, [
        'received_from' => 'คุณสมชาย IT Acme', 'received_on' => '2026-10-20', 'file' => UploadedFile::fake()->createWithContent('rules.pdf', '%PDF-1.4
1 0 obj<<>>endobj
trailer<<>>
%%EOF'),
    ])->assertSessionHasNoErrors();
    // Version 2 only from next month: version 1 stays in effect until then.
    ($this->publish)($room, ['summary' => ['กฎใหม่'], 'effective_on' => '2026-11-15'])->assertSessionHasNoErrors();

    $versions = RoomRuleVersion::orderBy('version')->get();
    expect($versions->pluck('version')->all())->toBe([1, 2])
        ->and($versions[0]->summary)->toBe(['ห้ามนำอาหารเข้า', 'ต้องมีเจ้าหน้าที่ลูกค้าอยู่ด้วย'])
        ->and($versions[0]->only(['received_from', 'created_by_name']))->toBe(['received_from' => 'คุณสมชาย IT Acme', 'created_by_name' => 'Admin Boss'])
        ->and($room->currentRules()->first()->version)->toBe(1)
        ->and(fn () => $versions[0]->update(['summary' => ['แก้ทับ']]))->toThrow(LogicException::class)
        ->and(RoomRuleVersion::find($versions[0]->id)->summary)->toBe(['ห้ามนำอาหารเข้า', 'ต้องมีเจ้าหน้าที่ลูกค้าอยู่ด้วย'])
        ->and(Activity::where('event', 'room_rules_published')->count())->toBe(2);

    $this->actingAs($this->admin)->get("/room-access/rooms/{$room->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('currentVersion', 1)->has('versions', 2)->where('versions.1.file', fn ($url) => $url !== null));
    $this->actingAs($this->admin)->get("/room-access/rooms/{$room->ulid}/rules/{$versions[0]->id}/file")->assertOk();

    $this->travelTo('2026-11-15 08:00');
    expect($room->currentRules()->first()->version)->toBe(2);
});

it('tells the admin about rooms with no rules yet', function () {
    ($this->room)()->assertSessionHasNoErrors();
    ($this->room)(['name' => 'Room 2'])->assertSessionHasNoErrors();
    ($this->publish)(ServerRoom::where('name', 'Room 2')->sole());

    $this->actingAs($this->admin)->get('/room-access/rooms')->assertInertia(fn (Assert $page) => $page
        ->where('missingRules', 1)->where('rooms.total', 2));
    $this->actingAs($this->admin)->get('/room-access/rooms?rules=missing')->assertInertia(fn (Assert $page) => $page
        ->where('rooms.total', 1)->where('rooms.data.0.name', 'Server Room 1'));
});

it('saves the company own terms and how long ID numbers are kept', function () {
    $this->actingAs($this->admin)->get('/room-access/rooms')->assertInertia(fn (Assert $page) => $page
        ->where('settings.id_retention_days', 90)->where('settings.company_terms', fn ($terms) => count($terms) === 3));

    $this->actingAs($this->admin)->put('/room-access/settings', ['company_terms' => ['แจ้งหัวหน้างานก่อนเข้า', ''], 'id_retention_days' => 3])
        ->assertSessionHasErrors('id_retention_days');
    $this->actingAs($this->admin)->put('/room-access/settings', ['company_terms' => ['แจ้งหัวหน้างานก่อนเข้า', ''], 'id_retention_days' => 120])
        ->assertSessionHasNoErrors();

    expect($this->tenant->fresh()->settings['room_access'])->toBe(['company_terms' => ['แจ้งหัวหน้างานก่อนเข้า'], 'id_retention_days' => 120])
        ->and(Activity::where('event', 'room_access_settings_updated')->count())->toBe(1);
});

it('is for whoever manages rooms, and only within the company', function () {
    ($this->room)()->assertSessionHasNoErrors();
    $room = ServerRoom::sole();

    foreach ([userWithRole('technician'), $this->desk] as $user) {
        $this->actingAs($user)->get('/room-access/rooms')->assertForbidden();
        $this->actingAs($user)->post("/room-access/rooms/{$room->ulid}/rules", ['summary' => ['x'], 'effective_on' => '2026-10-31'])->assertForbidden();
    }

    $other = createTenant('other');
    $otherAdmin = userWithRole('admin_company', [], $other);
    $this->actingAs($otherAdmin)->get("/room-access/rooms/{$room->ulid}")->assertNotFound();
    $this->actingAs($otherAdmin)->post("/room-access/rooms/{$room->ulid}/rules", ['summary' => ['x'], 'effective_on' => '2026-10-31'])->assertNotFound();
    $this->actingAs($otherAdmin)->get('/room-access/rooms')->assertInertia(fn (Assert $page) => $page->where('rooms.total', 0));

    // Switched off for the company: gone.
    Feature::for($this->tenant)->deactivate(Modules::feature('room_access'));
    $this->actingAs($this->admin)->get('/room-access/rooms')->assertNotFound();
});
