<?php

use App\Modules\RoomAccess\Actions\PublishRoomRules;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\Tenancy\Support\CompanyCodes;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The permit of an approved request: on paper (PDF / print) with the entrants, the time, the
 * equipment and the rules accepted; its QR link at the guard's counter without signing in (names
 * only, never phones or ID numbers), valid while the request is and until revoked or expired.
 */

beforeEach(function () {
    CompanyCodes::forget();
    config(['app.url' => 'http://localhost', 'tenancy.public_links' => 'path']);
    $this->travelTo('2026-11-02 09:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->room = ServerRoom::create(['customer_id' => createCustomer(['name' => 'Acme'])->id, 'name' => 'DC Room 1', 'location' => 'ชั้น 3', 'requires_id_number' => true]);
    $this->v1 = app(PublishRoomRules::class)->handle($this->room, ['summary' => ['ห้ามนำอาหารเข้า', 'ต้องมีเจ้าหน้าที่ลูกค้าอยู่ด้วย'], 'effective_on' => '2026-11-01'], null, $this->admin);

    $this->actingAs($this->tech)->post('/room-access/requests', [
        'server_room_id' => $this->room->id, 'planned_start' => '2026-11-05 10:00', 'planned_end' => '2026-11-05 12:00',
        'purpose' => 'บำรุงรักษาตามรอบ (PM)',
        'people' => [['name' => 'Tech One', 'company' => 'ITSol', 'phone' => '0811111111', 'id_number' => '1234567890123'], ['name' => 'Somsak Helper', 'company' => 'ITSol', 'id_number' => '3210987654321']],
        'items' => [['name' => 'HDD 2TB', 'serial_number' => 'HDD-777', 'quantity' => 2, 'direction' => 'in']],
        'submit' => true, 'accept' => true, 'version_id' => $this->v1->id,
    ])->assertSessionHasNoErrors();
    $this->request = RoomAccessRequest::latest('id')->first();
    $this->approve = fn () => $this->actingAs($this->desk)->post("/room-access/requests/{$this->request->ulid}/decide", ['decision' => 'approve'])->assertSessionHasNoErrors();
    $this->token = fn () => RoomAccessToken::where('request_id', $this->request->id)->where('purpose', 'permit')->whereNull('revoked_at')->value('token');
});

it('has no permit before it is approved', function () {
    $this->actingAs($this->tech)->get("/room-access/requests/{$this->request->ulid}/permit/print")->assertNotFound();
    $this->actingAs($this->tech)->get("/room-access/requests/{$this->request->ulid}")->assertInertia(fn (Assert $page) => $page->where('permit', null));
    expect(RoomAccessToken::count())->toBe(0);
});

it('prints the permit with who goes in, when, the equipment and the rules accepted', function () {
    ($this->approve)();
    $token = ($this->token)();

    $this->actingAs($this->tech)->get("/room-access/requests/{$this->request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('permit.link', "http://localhost/t/001/room-permit/{$token}")
        ->where('permit.valid', true)->where('permit.qr', fn ($svg) => str_contains($svg, '<svg'))
        ->where('permit.can_renew', true));

    $html = $this->actingAs($this->tech)->get("/room-access/requests/{$this->request->ulid}/permit/print")->assertOk()->getContent();
    expect($html)
        ->toContain('ใบอนุญาตเข้าพื้นที่ห้อง Server')->toContain($this->request->request_no)
        ->toContain('Acme')->toContain('DC Room 1')->toContain('ชั้น 3')
        // Buddhist year on documents.
        ->toContain('2569')
        ->toContain('Tech One')->toContain('Somsak Helper')->toContain('0811111111')
        // ID numbers masked, never in full.
        ->toContain('1-23XX-XXXXX-XX-3')->not->toContain('1234567890123')
        ->toContain('HDD 2TB')->toContain('HDD-777')
        ->toContain('ยอมรับเงื่อนไขของ Acme / DC Room 1 เวอร์ชัน 1')->toContain('ห้ามนำอาหารเข้า')
        ->toContain('Desk One')
        ->toContain('<svg')
        ->toContain('Sarabun');
});

it('renders the PDF through the PDF service', function () {
    ($this->approve)();
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf'])]);

    $this->actingAs($this->tech)->get("/room-access/requests/{$this->request->ulid}/permit")->assertOk()->assertHeader('content-type', 'application/pdf');
    Http::assertSent(fn (HttpRequest $sent) => str_contains($sent->url(), 'gotenberg.test'));
});

it('shows the permit at the counter without signing in: names only, valid while it is', function () {
    ($this->approve)();
    $token = ($this->token)();

    $this->get("/t/001/room-permit/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('RoomAccess/Permit')
        ->where('permit.valid', true)
        ->where('permit.customer', 'Acme')
        ->where('permit.people', [['name' => 'Tech One', 'company' => 'ITSol', 'phone' => null, 'id_number' => null], ['name' => 'Somsak Helper', 'company' => 'ITSol', 'phone' => null, 'id_number' => null]])
        ->where('permit.items.0.serial_number', 'HDD-777'));

    // Wrong link, or the right link under another company's code: not found.
    $this->get('/t/001/room-permit/'.str_repeat('x', 40))->assertInertia(fn (Assert $page) => $page->where('permit', null));
    $other = createTenant('other');
    CompanyCodes::forget();
    $this->get('/t/'.$other->fresh()->company_code."/room-permit/{$token}")->assertInertia(fn (Assert $page) => $page->where('permit', null));
});

it('stops the old link when a new one is made, and every link when the request is cancelled or the time has passed', function () {
    ($this->approve)();
    $old = ($this->token)();

    $this->actingAs(userWithRole('technician'))->post("/room-access/requests/{$this->request->ulid}/permit/renew")->assertForbidden();
    $this->actingAs($this->tech)->post("/room-access/requests/{$this->request->ulid}/permit/renew")->assertSessionHasNoErrors();
    $new = ($this->token)();
    expect($new)->not->toBe($old);
    auth()->logout();
    $this->get("/t/001/room-permit/{$old}")->assertInertia(fn (Assert $page) => $page->where('permit.valid', false));
    $this->get("/t/001/room-permit/{$new}")->assertInertia(fn (Assert $page) => $page->where('permit.valid', true));

    // Past the planned end plus the grace: expired.
    $this->travelTo('2026-11-06 00:01');
    $this->get("/t/001/room-permit/{$new}")->assertInertia(fn (Assert $page) => $page->where('permit.valid', false));

    $this->travelTo('2026-11-02 10:00');
    $this->actingAs($this->tech)->post("/room-access/requests/{$this->request->ulid}/cancel", ['reason' => 'ลูกค้าเลื่อน'])->assertSessionHasNoErrors();
    auth()->logout();
    $this->get("/t/001/room-permit/{$new}")->assertInertia(fn (Assert $page) => $page->where('permit.valid', false)->where('permit.request.status', 'cancelled'));
});
