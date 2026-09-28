<?php

use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Notifications\TicketNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-06-15 09:00');

    $this->admin = userWithRole('admin_company', ['name' => 'Approver']);
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Dispatcher']);
    $this->tech = userWithRole('technician', ['name' => 'Tech']);

    $customer = createCustomer();
    $asset = createAsset(createAssetCategory(), ['customer_id' => $customer->id]);
    $contract = createContract($customer, ['service_window' => '24x7', 'slas' => ['high' => ['response_minutes' => 30, 'resolve_minutes' => 120]]]);
    $contract->contractAssets()->create(['asset_id' => $asset->id]);

    $this->ticket = openTicket($this->helpdesk, [
        'customer_id' => $customer->id, 'asset_id' => $asset->id, 'contract_id' => $contract->id, 'priority' => 'high',
    ]);
});

it('tells the assignee, but not whoever assigned themselves', function () {
    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => $this->tech->id]);

    Notification::assertSentTo($this->tech, TicketNotification::class, function ($notification) {
        $mail = $notification->toMail($this->tech);

        return $notification->event === 'assigned'
            && $mail->subject === "[{$this->ticket->ticket_no}] คุณได้รับมอบหมายงาน"
            && str_contains($mail->actionUrl, $this->ticket->ulid);
    });

    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => $this->helpdesk->id]);
    Notification::assertNotSentTo($this->helpdesk, TicketNotification::class);
});

it('asks the approvers to confirm a fix on a staff-opened ticket', function () {
    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => $this->tech->id]);
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/move", ['action' => 'start']);
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/move", ['action' => 'resolve']);

    Notification::assertSentTo($this->admin, TicketNotification::class, fn ($n) => $n->event === 'resolved');
    Notification::assertNotSentTo($this->helpdesk, TicketNotification::class, fn ($n) => $n->event === 'resolved');
});

it('e-mails each SLA breach once, skips tickets on hold, and again after the due time moves', function () {
    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => $this->tech->id]);

    // 24x7: response due 09:30, resolve due 11:00
    $this->travelTo('2026-06-15 09:45');
    $this->artisan('tickets:notify-sla-breaches')->assertSuccessful();
    Notification::assertSentTo($this->tech, TicketNotification::class, fn ($n) => $n->event === 'response_breached');
    Notification::assertSentTo($this->helpdesk, TicketNotification::class, fn ($n) => $n->event === 'response_breached');

    $this->artisan('tickets:notify-sla-breaches');
    Notification::assertSentToTimes($this->tech, TicketNotification::class, 2); // assigned + one breach

    // on hold at 10:00, the resolve due time passes while waiting
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/move", ['action' => 'start']);
    $this->travelTo('2026-06-15 10:00');
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/move", ['action' => 'hold', 'comment' => 'รออะไหล่']);
    $this->travelTo('2026-06-15 11:30');
    $this->artisan('tickets:notify-sla-breaches');
    Notification::assertNotSentTo($this->tech, TicketNotification::class, fn ($n) => $n->event === 'resolve_breached');

    // back to work at 12:00: 2h on hold -> resolve due 13:00, then missed
    $this->travelTo('2026-06-15 12:00');
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/move", ['action' => 'start']);
    expect($this->ticket->fresh()->resolve_due_at->format('H:i'))->toBe('13:00');

    $this->travelTo('2026-06-15 13:05');
    $this->artisan('tickets:notify-sla-breaches');
    Notification::assertSentTo($this->tech, TicketNotification::class, fn ($n) => $n->event === 'resolve_breached');
    expect($this->ticket->fresh()->resolve_breach_notified_at)->not->toBeNull();
});

it('skips tenants with the service module off', function () {
    Feature::for($this->tenant)->deactivate(Modules::feature('service'));
    $this->travelTo('2026-06-15 12:00');

    $this->artisan('tickets:notify-sla-breaches');

    expect(Ticket::first()->response_breach_notified_at)->toBeNull();
});

it('checks for SLA breaches every 15 minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'tickets:notify-sla-breaches'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *');
});
