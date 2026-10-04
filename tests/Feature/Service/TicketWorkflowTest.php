<?php

use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday 09:00
    $this->travelTo('2026-06-15 09:00');

    allowAllBranches('helpdesk'); // a head-office dispatcher
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Helpdesk Here']);
    $this->north = Branch::create(['code' => 'N', 'name' => 'North']);
    $this->south = Branch::create(['code' => 'S', 'name' => 'South']);
    $this->tech = userWithRole('technician', ['name' => 'Tech North', 'branch_id' => $this->north->id]);
    $this->otherTech = userWithRole('technician', ['name' => 'Tech South', 'branch_id' => $this->south->id]);

    $customer = createCustomer();
    $asset = createAsset(createAssetCategory(), ['customer_id' => $customer->id, 'branch_id' => $this->north->id]);
    $contract = createContract($customer, ['service_window' => '8x5', 'slas' => ['high' => ['response_minutes' => 120, 'resolve_minutes' => 480]]]);
    $contract->contractAssets()->create(['asset_id' => $asset->id]);

    $this->ticket = openTicket($this->helpdesk, [
        'customer_id' => $customer->id, 'asset_id' => $asset->id, 'contract_id' => $contract->id, 'priority' => 'high',
    ]);
});

function moveTicket(Ticket $ticket, string $action, ?string $comment = null)
{
    return test()->post("/tickets/{$ticket->ulid}/move", array_filter(['action' => $action, 'comment' => $comment]));
}

it('runs a ticket from assignment to approval and keeps the SLA clock right', function () {
    // 8x5: Monday 09:00 + 8 business hours = Monday 17:00
    expect($this->ticket->resolve_due_at->format('Y-m-d H:i'))->toBe('2026-06-15 17:00');

    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => $this->tech->id])->assertSessionHasNoErrors();

    $this->travelTo('2026-06-15 10:00');
    $this->actingAs($this->tech);
    moveTicket($this->ticket, 'start')->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->responded_at->format('H:i'))->toBe('10:00');

    // on hold 11:00 -> 14:00 = 3 business hours added to the resolve time
    $this->travelTo('2026-06-15 11:00');
    moveTicket($this->ticket, 'hold')->assertSessionHasErrors('comment');
    moveTicket($this->ticket, 'hold', 'รออะไหล่จากผู้ขาย')->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_ON_HOLD);

    $this->travelTo('2026-06-15 14:00');
    moveTicket($this->ticket, 'start')->assertSessionHasNoErrors();
    $ticket = $this->ticket->fresh();
    expect($ticket->hold_minutes)->toBe(180)
        ->and($ticket->resolve_due_at->format('Y-m-d H:i'))->toBe('2026-06-16 11:00')
        ->and($ticket->responded_at->format('H:i'))->toBe('10:00');

    $this->travelTo('2026-06-15 15:00');
    moveTicket($this->ticket, 'resolve')->assertSessionHasNoErrors();
    moveTicket($this->ticket, 'approve')->assertForbidden();

    // Closing is for tickets.approve (the customer) or tickets.close (helpdesk, admin), not the technician.
    expect($this->helpdesk->can('approve', $this->ticket->fresh()))->toBeTrue();

    $admin = userWithRole('admin_company');
    $this->actingAs($admin);
    moveTicket($this->ticket, 'reject', 'ยังพิมพ์ไม่ออก')->assertSessionHasNoErrors();
    expect($this->ticket->fresh()->only(['status', 'resolved_at']))->toBe(['status' => 'in_progress', 'resolved_at' => null]);

    $this->actingAs($this->tech);
    moveTicket($this->ticket, 'resolve');
    $this->actingAs($admin);
    moveTicket($this->ticket, 'approve')->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();
    expect($ticket->status)->toBe(Ticket::STATUS_CLOSED)
        ->and($ticket->closed_at)->not->toBeNull()
        ->and($ticket->events()->where('type', 'status')->pluck('to_status')->all())
        ->toBe(['in_progress', 'on_hold', 'in_progress', 'resolved', 'in_progress', 'resolved', 'closed'])
        ->and($ticket->events()->where('to_status', 'on_hold')->value('body'))->toBe('รออะไหล่จากผู้ขาย');
});

it('refuses moves that the status does not allow', function () {
    $this->actingAs(userWithRole('admin_company'));

    moveTicket($this->ticket, 'approve')->assertSessionHasErrors('action');
    moveTicket($this->ticket, 'nonsense')->assertSessionHasErrors('action');
    moveTicket($this->ticket, 'cancel', 'ลูกค้าแจ้งซ้ำ')->assertSessionHasNoErrors();
    moveTicket($this->ticket, 'cancel', 'again')->assertSessionHasErrors('action');

    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_CANCELLED);
});

it('lets only the assignee or a dispatcher work on a ticket', function () {
    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => $this->tech->id]);

    // same branch, not the assignee (scope own): cannot even see it
    $colleague = userWithRole('technician', ['branch_id' => $this->north->id]);
    $this->actingAs($colleague)->get("/tickets/{$this->ticket->ulid}")->assertForbidden();
    moveTicket($this->ticket, 'start')->assertForbidden();

    // with scope branch the colleague sees it but still cannot work on it
    setRoleScope('technician', 'branch', ['tickets.view', 'tickets.update']);
    $this->actingAs($colleague)->get("/tickets/{$this->ticket->ulid}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('actions', [])->where('can.update', true));
    moveTicket($this->ticket, 'start')->assertForbidden();

    // other branch: cannot even see it
    $this->actingAs($this->otherTech)->get("/tickets/{$this->ticket->ulid}")->assertForbidden();

    // technicians cannot assign or cancel
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => $colleague->id])->assertForbidden();
    moveTicket($this->ticket, 'cancel', 'x')->assertForbidden();
});

it('lets the assignee see a ticket of another branch', function () {
    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/assign", ['assignee_id' => $this->otherTech->id]);

    $this->actingAs($this->otherTech)->get("/tickets/{$this->ticket->ulid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('actions', ['start']));
});

it('shows the buttons the user may press', function () {
    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('actions', ['cancel'])
            ->where('assignees', fn ($users) => collect($users)->pluck('name')->contains('Tech North')));
});

it('moves the due times when the priority changes', function () {
    $this->actingAs($this->helpdesk)->put("/tickets/{$this->ticket->ulid}", [
        'title' => 'Now urgent', 'priority' => 'low', 'source' => 'email',
    ])->assertSessionHasNoErrors();

    $ticket = $this->ticket->fresh();
    expect($ticket->resolve_minutes)->toBeNull()
        ->and($ticket->resolve_due_at)->toBeNull()
        ->and($ticket->events()->where('type', 'updated')->latest('id')->value('body'))->toBe('หัวข้อ, ความเร่งด่วน, ช่องทางแจ้ง');
});

it('adds comments and internal notes', function () {
    $this->actingAs($this->helpdesk)->post("/tickets/{$this->ticket->ulid}/comments", ['body' => 'โทรหาลูกค้าแล้ว', 'is_internal' => true])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('events', fn ($events) => collect($events)->where('type', 'comment')
                ->map(fn ($e) => [$e['body'], $e['is_internal'], $e['user_name']])->values()->all() === [['โทรหาลูกค้าแล้ว', true, 'Helpdesk Here']]));
});

it('needs the warranty checked before work starts', function () {
    $ticket = openTicket($this->helpdesk, ['title' => 'No warranty yet'], warrantyChecked: false);
    $this->actingAs($this->helpdesk)->post("/tickets/{$ticket->ulid}/assign", ['assignee_id' => $this->tech->id]);

    $this->actingAs($this->tech);
    moveTicket($ticket, 'start')->assertSessionHasErrors(['action' => 'กรุณาตรวจสอบการรับประกันของเครื่องก่อนเริ่มดำเนินการ']);
    expect($ticket->fresh()->status)->toBe(Ticket::STATUS_ASSIGNED);

    $this->post("/tickets/{$ticket->ulid}/warranty", ['warranty_status' => 'in_warranty'])->assertSessionHasNoErrors();
    moveTicket($ticket, 'start')->assertSessionHasNoErrors();
    expect($ticket->fresh())->warranty_status->toBe('in_warranty')->status->toBe(Ticket::STATUS_IN_PROGRESS);
});

it('needs the repair report before the job is done or closed', function () {
    $ticket = openTicket($this->helpdesk, reportFilled: false);
    app(AssignTicket::class)->handle($ticket, $this->tech->id, $this->helpdesk);
    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/move", ['action' => 'start'])->assertSessionHasNoErrors();

    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/move", ['action' => 'resolve'])->assertSessionHasErrors('action');
    expect($ticket->fresh()->status)->toBe('in_progress');

    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/report", ['cause' => 'Fan dead', 'approver_name' => 'Khun A'])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("/tickets/{$ticket->ulid}/move", ['action' => 'resolve'])->assertSessionHasNoErrors();
    expect($ticket->fresh()->status)->toBe('resolved');
});
