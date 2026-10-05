<?php

use App\Modules\Labeling\Actions\QrSvg;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Actions\MoveTicket;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Notifications\CustomerTicketMail;
use App\Modules\Tenancy\Support\CompanyCodes;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CompanyCodes::forget();
    config(['app.url' => 'http://localhost', 'tenancy.public_links' => 'path']);
    $this->helpdesk = userWithRole('helpdesk');
    $this->asset = createAsset(createAssetCategory());
    $this->key = $this->asset->fresh()->public_key;
    $this->report = fn (array $data = []) => $this->post("/t/001/q/{$this->asset->asset_code}/report?k={$this->key}", $data + [
        'symptoms' => ['เปิดไม่ติด'], 'name' => 'Khun Customer', 'email' => 'cust@example.com',
    ]);
});

it('puts on the job sheet a QR code that opens the tracking page of that very ticket', function () {
    $ticket = openTicket($this->helpdesk, ['title' => 'Printer']);

    // What the QR code holds.
    $encoded = [];
    app()->instance(QrSvg::class, new class($encoded) extends QrSvg
    {
        public function __construct(private array &$seen) {}

        public function handle(string $text): string
        {
            $this->seen[] = $text;

            return '<svg/>';
        }
    });

    $this->actingAs($this->helpdesk)->get("/tickets/{$ticket->ulid}/print")->assertInertia(fn (Assert $page) => $page
        ->where('tracking.qr', '<svg/>')
        ->where('tracking.search', 'localhost/track')
        ->where('ticket.ticket_no', $ticket->ticket_no));

    expect($encoded)->toBe(["http://localhost/t/001/track/{$ticket->tracking_token}"]);
    auth()->logout();
    $this->get(parse_url($encoded[0], PHP_URL_PATH))->assertInertia(fn (Assert $page) => $page->where('ticket.ticket_no', $ticket->ticket_no));
});

it('e-mails the reporter when the report is received, accepted and done', function () {
    Notification::fake();
    ($this->report)();
    $ticket = Ticket::first();

    $this->actingAs($this->helpdesk)->post("/tickets/{$ticket->ulid}/review", ['decision' => 'accept', 'message' => 'จะเข้าไปพรุ่งนี้']);
    $tech = userWithRole('technician');
    app(AssignTicket::class)->handle($ticket->fresh(), $tech->id, $this->helpdesk);
    checkWarranty($ticket->fresh(), $tech);
    $ticket->forceFill(['cause' => 'x', 'approver_name' => 'y'])->save();
    app(MoveTicket::class)->handle($ticket->fresh(), 'start', $tech);
    app(MoveTicket::class)->handle($ticket->fresh(), 'resolve', $tech);

    $events = [];
    Notification::assertSentTo(new AnonymousNotifiable, CustomerTicketMail::class, function ($mail, $channels, $notifiable) use (&$events) {
        $events[] = $mail->event;

        return $notifiable->routes['mail'] === 'cust@example.com';
    });
    expect($events)->toBe(['received', 'accepted', 'done']);

    // The mail has the tracking link and the office's message, nothing internal.
    $mail = (new CustomerTicketMail($ticket->fresh(), 'accepted'))->toMail(new AnonymousNotifiable);
    expect($mail->actionUrl)->toBe("http://localhost/t/001/track/{$ticket->tracking_token}")
        ->and(implode(' ', $mail->introLines))->toContain('จะเข้าไปพรุ่งนี้')->not->toContain($tech->name);
});

it('sends nothing to a reporter who left only a phone number', function () {
    Notification::fake();
    ($this->report)(['email' => null, 'phone' => '0812345678']);
    Notification::assertNothingSent();
});

it('blanks out reporters once the company stops keeping them, and only theirs', function () {
    ($this->report)();
    $old = Ticket::first();
    $old->forceFill(['status' => Ticket::STATUS_CANCELLED, 'cancelled_at' => now()->subDays(181)])->save();
    $recent = openTicket($this->helpdesk, ['contact_name' => 'Staff Caller']);
    $recent->forceFill(['status' => Ticket::STATUS_CLOSED, 'closed_at' => now()->subDays(400)])->save();

    $this->artisan('tickets:anonymize-reporters')->assertSuccessful();

    expect($old->fresh()->only(['contact_name', 'contact_phone', 'contact_email']))
        ->toBe(['contact_name' => '(ปกปิดข้อมูลผู้แจ้งแล้ว)', 'contact_phone' => null, 'contact_email' => null])
        ->and($old->fresh()->reporter_anonymized_at)->not->toBeNull()
        // A ticket staff opened is not a QR report: kept.
        ->and($recent->fresh()->contact_name)->toBe('Staff Caller');

    // The company may keep them longer.
    $this->actingAs(userWithRole('admin_company'))->put('/company', ['reporter_retention_days' => 365])->assertSessionHasNoErrors();
    expect($this->tenant->fresh()->settings['reporter_retention_days'])->toBe(365);
});
