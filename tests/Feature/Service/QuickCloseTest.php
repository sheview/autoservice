<?php

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Actions\SyncRoleGrants;
use App\Modules\Identity\Models\Role;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Models\RepairPreset;
use App\Modules\Service\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);
    $this->part = createPart(['name' => 'Fan'], 3);

    // Assigned, warranty not checked yet, no repair report.
    $this->ticket = openTicket($this->helpdesk, ['customer_id' => $this->customer->id], warrantyChecked: false, reportFilled: false);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);

    $this->close = fn (array $data, $user = null) => $this->actingAs($user ?? $this->tech)
        ->post("/tickets/{$this->ticket->ulid}/close", $data + ['symptoms' => ['ร้อนผิดปกติ'], 'solutions' => ['เปลี่ยนอะไหล่'], 'approver_name' => 'Khun A', 'warranty_status' => 'out_of_warranty']);
    $this->signature = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('s.png', 10, 10)->getContent());
});

it('closes a job in one go: report, photos, parts asked for, place, then resolved', function () {
    $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ulid}/close")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Service/Tickets/Close')
        ->where('presets.symptom', fn ($list) => in_array('เปิดไม่ติด', collect($list)->all(), true))
        ->where('signatureRequired', false)
        ->where('canRequestParts', true)
        ->where('ticket.warranty_checked', false));

    ($this->close)([
        'parts' => [['part_id' => $this->part->id, 'qty' => 2]],
        'before' => [UploadedFile::fake()->image('b.jpg')],
        'after' => [UploadedFile::fake()->image('a1.jpg'), UploadedFile::fake()->image('a2.jpg')],
        'lat' => 13.7563, 'lng' => 100.5018,
    ])->assertSessionHasNoErrors()->assertRedirect("/tickets/{$this->ticket->ulid}");

    $ticket = $this->ticket->fresh();
    expect($ticket->status)->toBe(Ticket::STATUS_RESOLVED)
        ->and($ticket->cause)->toContain('ร้อนผิดปกติ')->toContain('เปลี่ยนอะไหล่')
        ->and($ticket->approver_name)->toBe('Khun A')
        ->and($ticket->warranty_status)->toBe('out_of_warranty')
        ->and((float) $ticket->closed_lat)->toBe(13.7563)
        ->and($ticket->getMedia(Ticket::PHOTOS)->map(fn ($m) => $m->getCustomProperty('stage'))->sort()->values()->all())->toBe(['after', 'after', 'before'])
        // the parts wait on an issue request for the job: no stock moves until it is handed out
        ->and(Part::find($this->part->id)->qty_on_hand)->toBe(3)
        ->and(StockMovement::where('ticket_id', $ticket->id)->count())->toBe(0);

    $request = CheckoutRequest::with('items')->sole();
    expect($request->only(['ticket_id', 'status', 'requester_id', 'borrower_user_id']))->toBe([
        'ticket_id' => $ticket->id, 'status' => CheckoutRequest::STATUS_PENDING, 'requester_id' => $this->tech->id, 'borrower_user_id' => $this->tech->id,
    ])
        ->and($request->items->sole()->only(['part_id', 'qty_requested', 'ticket_id', 'for_internal', 'customer_name']))->toBe([
            'part_id' => $this->part->id, 'qty_requested' => 2, 'ticket_id' => $ticket->id, 'for_internal' => false, 'customer_name' => 'Acme',
        ]);
    $this->actingAs($this->tech)->get("/tickets/{$ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('parts.requests.0.request_no', $request->request_no));
});

it('asks for more than is in stock: what is missing is owed when handed out', function () {
    ($this->close)(['parts' => [['part_id' => $this->part->id, 'qty' => 2], ['part_id' => $this->part->id, 'qty' => 2]]])
        ->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_RESOLVED)
        ->and(Part::find($this->part->id)->qty_on_hand)->toBe(3)
        ->and(CheckoutRequest::sole()->items()->sum('qty_requested'))->toEqual(4);
});

it('takes parts only from whoever may ask for them', function () {
    $sync = app(SyncRoleGrants::class);
    $role = Role::findByName('technician');
    $sync->handle($role, array_diff_key($sync->grantsOf($role), array_flip(['asset-checkouts.request', 'asset-checkouts.create'])));

    $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ulid}/close")->assertInertia(fn (Assert $page) => $page->where('canRequestParts', false));
    ($this->close)(['parts' => [['part_id' => $this->part->id, 'qty' => 1]]])->assertForbidden();

    expect(CheckoutRequest::count())->toBe(0);
});

it('needs the customer to sign when their customer or contract asks for it', function () {
    $this->customer->update(['require_signature' => true]);
    $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ulid}/close")->assertInertia(fn (Assert $page) => $page->where('signatureRequired', true));

    ($this->close)([])->assertSessionHasErrors('signature');
    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_ASSIGNED);

    ($this->close)(['signature' => $this->signature, 'signer_name' => 'Khun Signer'])->assertSessionHasNoErrors();
    $ticket = $this->ticket->fresh();
    expect($ticket->status)->toBe(Ticket::STATUS_RESOLVED)
        ->and($ticket->approver_name)->toBe('Khun Signer')
        ->and($ticket->getFirstMedia(Ticket::SIGNATURE)?->getCustomProperty('signer'))->toBe('Khun Signer');
});

it('lets a contract say otherwise than its customer', function () {
    $this->customer->update(['require_signature' => true]);
    $contract = createContract($this->customer, ['require_signature' => false]);
    $this->ticket->forceFill(['contract_id' => $contract->id])->save();

    ($this->close)([])->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_RESOLVED);
});

it('lets only who may finish the job close it', function () {
    $this->actingAs(userWithRole('technician'))->get("/tickets/{$this->ticket->ulid}/close")->assertForbidden();
    ($this->close)([], userWithRole('technician'))->assertForbidden();
    ($this->close)([], userWithRole('user'))->assertForbidden();
    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_ASSIGNED);

    // A dispatcher may (tickets.assign over the company).
    ($this->close)([], $this->helpdesk)->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_RESOLVED);
});

it('lets the company admin keep its symptom and fix chips', function () {
    $admin = userWithRole('admin_company');
    $this->actingAs($this->tech)->get('/settings/repair-presets')->assertForbidden();

    $this->actingAs($admin)->put('/settings/repair-presets', ['symptom' => ['จอฟ้า', 'เสียงดัง'], 'solution' => ['ลงวินโดวส์ใหม่']])
        ->assertSessionHasNoErrors();
    expect(RepairPreset::where('kind', 'symptom')->orderBy('sort')->pluck('label')->all())->toBe(['จอฟ้า', 'เสียงดัง']);

    $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ulid}/close")
        ->assertInertia(fn (Assert $page) => $page->where('presets', ['symptom' => ['จอฟ้า', 'เสียงดัง'], 'solution' => ['ลงวินโดวส์ใหม่']]));

    // Kept per company.
    $other = createTenant('other');
    expect(asTenant($other, fn () => RepairPreset::count()))->toBe(0);
});

it('links to the one-page close from the ticket page and my work while the job can be closed there', function () {
    $closeUrl = route('service.tickets.close', $this->ticket);

    $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('can.quickClose', true));
    $this->actingAs($this->tech)->get('/my-work')
        ->assertInertia(fn (Assert $page) => $page->where('todo', fn ($items) => collect($items)->firstWhere('key', "ticket-{$this->ticket->id}")['close_href'] === $closeUrl));
    ($this->close)([])->assertSessionHasNoErrors();

    $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('can.quickClose', false));
});
