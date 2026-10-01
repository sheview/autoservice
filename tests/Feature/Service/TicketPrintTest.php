<?php

use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\AssignTicket;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Notification::fake();

    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->acme = createCustomer(['name' => 'Acme']);
    $this->asset = createAsset(createAssetCategory(), ['name' => 'Front desk PC', 'customer_id' => $this->acme->id]);

    $this->ticket = openTicket($this->helpdesk, [
        'title' => 'Printer jam', 'description' => 'Paper stuck in tray 2', 'customer_id' => $this->acme->id,
        'asset_id' => $this->asset->id, 'contact_name' => 'Khun Somchai', 'contact_phone' => '081-234-5678',
    ]);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);
    $this->url = "/tickets/{$this->ticket->ulid}/print";
});

it('renders the job sheet with the work notes and the parts used, without internal notes', function () {
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/move", ['action' => 'start']);
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/comments", ['body' => 'เปลี่ยนลูกยางดึงกระดาษ']);
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/comments", ['body' => 'ลูกค้าค้างชำระ', 'is_internal' => true]);
    $roller = createPart(['code' => 'ROLLER', 'name' => 'Pickup roller'], stock: 5);
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/parts", ['part_id' => $roller->id, 'quantity' => 2]);

    $this->actingAs($this->tech)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Service/Tickets/Print')
        ->where('company', 'Default')
        ->where('ticket.ticket_no', $this->ticket->ticket_no)
        ->where('ticket.title', 'Printer jam')
        ->where('ticket.customer', 'Acme')
        ->where('ticket.asset', ['asset_code' => $this->asset->asset_code, 'name' => 'Front desk PC'])
        ->where('ticket.contact_name', 'Khun Somchai')
        ->where('ticket.assignee', 'Tech One')
        ->where('ticket.status', 'in_progress')
        ->where('ticket.responded_at', fn ($at) => $at !== null)
        ->where('ticket.closed_at', null)
        ->where('notes', fn ($notes) => collect($notes)->pluck('body')->all() === ['เปลี่ยนลูกยางดึงกระดาษ'])
        ->where('parts', [['part_id' => $roller->id, 'code' => 'ROLLER', 'name' => 'Pickup roller', 'unit' => 'pcs', 'quantity' => 2, 'types' => ['issue']]]));
});

it('leaves the parts table out for users who do not see stock, or with the module off', function () {
    // a customer account may print its own ticket, without anything about stock
    $client = userWithRole('customer', ['customer_id' => $this->acme->id]);
    $this->actingAs($client)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts', null)->where('ticket.customer', 'Acme'));

    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts', []));
    Feature::for($this->tenant)->deactivate(Modules::feature('inventory'));
    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page->where('parts', null));
});

it('only prints tickets the user may see', function () {
    $this->get($this->url)->assertRedirect('/login');

    $otherClient = userWithRole('customer', ['customer_id' => createCustomer()->id]);
    $this->actingAs($otherClient)->get($this->url)->assertForbidden();

    $other = createTenant('other');
    $theirs = asTenant($other, fn () => openTicket(userWithRole('helpdesk')));
    $this->actingAs($this->helpdesk)->get("/tickets/{$theirs->ulid}/print")->assertNotFound();
});

it('fills the device part of the sheet from the asset when the ticket left it out', function () {
    $asset = createAsset(createAssetCategory(), [
        'name' => 'Notebook', 'brand' => 'Dell', 'model' => 'Latitude 5440', 'serial_number' => 'BC1373595',
        'property_no' => 'ครภ.69-001', 'location' => 'ห้องประชุม',
    ]);
    $ticket = openTicket($this->helpdesk, ['title' => 'Disk noise', 'asset_id' => $asset->id]);
    // a ticket opened before tickets kept the device details
    $ticket->forceFill(['device_name' => null, 'device_brand' => null, 'device_model' => null, 'device_serial' => null, 'device_location' => null])->save();

    $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ulid}/print")->assertInertia(fn (Assert $page) => $page
        ->where('ticket.device', [
            'registered' => true, 'name' => 'Notebook', 'brand' => 'Dell', 'model' => 'Latitude 5440', 'serial' => 'BC1373595',
            'serial_unknown' => false, 'location' => 'ห้องประชุม', 'ip' => null, 'property_no' => 'ครภ.69-001',
        ]));
});
