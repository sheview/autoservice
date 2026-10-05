<?php

use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Support\CompanyCodes;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CompanyCodes::forget();
    Storage::fake('public');
    $this->customer = createCustomer(['name' => 'Acme']);
    $this->asset = createAsset(createAssetCategory(['name' => 'Printer']), ['brand' => 'HP', 'customer_id' => $this->customer->id, 'serial_number' => 'SN-1']);
    $this->contract = createContract($this->customer);
    $this->contract->contractAssets()->create(['asset_id' => $this->asset->id]);
    $this->key = $this->asset->fresh()->public_key;
    $this->page = "/t/001/q/{$this->asset->asset_code}?k={$this->key}";
    $this->report = fn (array $data = [], ?string $key = null) => $this->post("/t/001/q/{$this->asset->asset_code}/report?k=".($key ?? $this->key), $data + [
        'symptoms' => ['พิมพ์ไม่ออก', 'กระดาษติด'],
        'name' => 'Khun Customer',
        'phone' => '081-234-5678',
    ]);
});

it('lets anyone report a problem with the QR code, which waits for the helpdesk', function () {
    $this->get($this->page)->assertInertia(fn (Assert $page) => $page
        ->where('canReport', true)
        ->where('running', null)
        ->where('symptoms', fn ($list) => collect($list)->contains('เปิดไม่ติด')));

    ($this->report)(['email' => 'cust@example.com', 'photos' => [UploadedFile::fake()->image('p.jpg')]])->assertSessionHasNoErrors();

    $ticket = Ticket::first();
    expect($ticket->only(['status', 'source', 'asset_id', 'customer_id', 'contact_name', 'contact_phone', 'contact_email', 'reported_by', 'response_due_at']))
        ->toBe(['status' => 'pending_review', 'source' => 'qr', 'asset_id' => $this->asset->id, 'customer_id' => $this->customer->id,
            'contact_name' => 'Khun Customer', 'contact_phone' => '081-234-5678', 'contact_email' => 'cust@example.com', 'reported_by' => null, 'response_due_at' => null])
        ->and($ticket->title)->toContain('พิมพ์ไม่ออก')
        ->and($ticket->getMedia(Ticket::PHOTOS))->toHaveCount(1);

    // "Received": the tracking link of the new ticket, with its number.
    $this->get("/t/001/track/{$ticket->tracking_token}")->assertInertia(fn (Assert $page) => $page
        ->where('ticket.ticket_no', $ticket->ticket_no)
        ->where('ticket.state', 'reviewing'));

    // In the helpdesk's open list, not in a technician's.
    $this->actingAs(userWithRole('helpdesk'))->get('/tickets')->assertInertia(fn (Assert $page) => $page->where('tickets.total', 1));
    $this->actingAs(userWithRole('technician'))->get('/tickets')->assertInertia(fn (Assert $page) => $page->where('tickets.total', 0));
});

it('opens no second ticket for a device already being looked after', function () {
    ($this->report)()->assertRedirect();
    $first = Ticket::first();

    $this->get($this->page)->assertInertia(fn (Assert $page) => $page->where('running', url("/t/001/track/{$first->tracking_token}")));
    ($this->report)(['name' => 'Someone else'])->assertRedirect(url("/t/001/track/{$first->tracking_token}"))->assertSessionHas('already', $first->ticket_no);

    expect(Ticket::count())->toBe(1);
});

it('trusts nothing from the public form', function () {
    // Many tries in a row here: the rate limits have their own test.
    $this->withoutMiddleware(ThrottleRequests::class);
    ($this->report)(['phone' => null])->assertSessionHasErrors(['phone', 'email']);
    ($this->report)(['symptoms' => []])->assertSessionHasErrors('symptoms');
    ($this->report)(['email' => 'not-an-email', 'phone' => null])->assertSessionHasErrors('email');
    ($this->report)(['photos' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('photos.0');
    expect(Ticket::count())->toBe(0);

    // A robot filling the hidden field is thanked, and nothing is made.
    ($this->report)(['website' => 'http://spam.example'])->assertRedirect();
    // A wrong key reports nothing.
    ($this->report)([], 'wrongkey')->assertRedirect();
    expect(Ticket::count())->toBe(0);
});

it('lets the helpdesk accept, ask for more, or turn down a report, and the customer sees only the answer', function () {
    $helpdesk = userWithRole('helpdesk');
    ($this->report)();
    $ticket = Ticket::first();
    $track = fn () => $this->get("/t/001/track/{$ticket->tracking_token}");

    $this->actingAs($helpdesk)->get("/tickets/{$ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('review.contracts.0.contract_no', $this->contract->contract_no));
    $this->actingAs(userWithRole('technician'))->post("/tickets/{$ticket->ulid}/review", ['decision' => 'accept'])->assertForbidden();

    $this->actingAs($helpdesk)->post("/tickets/{$ticket->ulid}/review", ['decision' => 'ask'])->assertSessionHasErrors('message');
    $this->actingAs($helpdesk)->post("/tickets/{$ticket->ulid}/review", ['decision' => 'ask', 'message' => 'รุ่นเครื่องพิมพ์อะไรครับ'])->assertSessionHasNoErrors();
    expect($ticket->fresh()->status)->toBe('pending_review');
    auth()->logout();
    $track()->assertInertia(fn (Assert $page) => $page->where('ticket.state', 'reviewing')->where('ticket.message', 'รุ่นเครื่องพิมพ์อะไรครับ'));

    $this->actingAs($helpdesk)->post("/tickets/{$ticket->ulid}/review", ['decision' => 'accept'])->assertSessionHasNoErrors();
    expect($ticket->fresh()->only(['status', 'contract_id']))->toBe(['status' => 'new', 'contract_id' => $this->contract->id]);
    auth()->logout();
    $track()->assertInertia(fn (Assert $page) => $page->where('ticket.state', 'received'));
});

it('tells the customer why a report was turned down', function () {
    ($this->report)();
    $ticket = Ticket::first();
    $this->actingAs(userWithRole('helpdesk'))->post("/tickets/{$ticket->ulid}/review", ['decision' => 'reject', 'message' => 'เครื่องนี้หมดสัญญาแล้ว'])
        ->assertSessionHasNoErrors();
    auth()->logout();

    expect($ticket->fresh()->status)->toBe('cancelled');
    $this->get("/t/001/track/{$ticket->tracking_token}")->assertInertia(fn (Assert $page) => $page
        ->where('ticket.state', 'rejected')->where('ticket.message', 'เครื่องนี้หมดสัญญาแล้ว'));
});
