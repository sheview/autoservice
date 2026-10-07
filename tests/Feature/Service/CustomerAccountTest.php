<?php

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Notifications\TicketNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-06-15 09:00');

    $this->admin = userWithRole('admin_company', ['name' => 'Admin Here']);
    $this->helpdesk = userWithRole('helpdesk', ['name' => 'Dispatcher']);

    $this->acme = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $this->beta = createCustomer(['code' => 'BETA', 'name' => 'Beta']);
    $this->acmeAsset = createAsset(createAssetCategory(), ['name' => 'Acme PC', 'customer_id' => $this->acme->id]);
    $this->betaAsset = createAsset(createAssetCategory(), ['name' => 'Beta PC', 'customer_id' => $this->beta->id]);
    $this->ownAsset = createAsset(createAssetCategory(), ['name' => 'Our own PC']);

    $contract = createContract($this->acme, ['service_window' => '24x7', 'slas' => ['medium' => ['response_minutes' => 60, 'resolve_minutes' => 240]]]);
    $contract->contractAssets()->create(['asset_id' => $this->acmeAsset->id]);

    $this->client = userWithRole('customer_it', ['name' => 'Acme IT', 'customer_id' => $this->acme->id]);
});

it('lets an admin create a customer account, and only with the customer role', function () {
    $payload = ['name' => 'Beta IT', 'email' => 'it@beta.test', 'password' => 'Password-123', 'password_confirmation' => 'Password-123'];

    $this->actingAs($this->admin)->post('/users', $payload + ['role' => 'customer_it'])->assertSessionHasErrors('customer_id');
    $this->actingAs($this->admin)->post('/users', $payload + ['role' => 'technician', 'customer_id' => $this->beta->id])->assertSessionHasErrors('role');
    $this->actingAs($this->admin)->post('/users', $payload + ['role' => 'customer_it', 'customer_id' => $this->beta->id])->assertSessionHasNoErrors();

    expect(User::where('email', 'it@beta.test')->value('customer_id'))->toBe($this->beta->id);

    $this->actingAs($this->admin)->get('/users?search=beta')
        ->assertInertia(fn (Assert $page) => $page->where('users.data.0.customer', 'Beta'));
});

it('shows a customer account only its own assets and tickets', function () {
    openTicket($this->helpdesk, ['title' => 'Acme job', 'customer_id' => $this->acme->id, 'asset_id' => $this->acmeAsset->id]);
    $betaTicket = openTicket($this->helpdesk, ['title' => 'Beta job', 'customer_id' => $this->beta->id, 'asset_id' => $this->betaAsset->id]);

    $this->actingAs($this->client)->get('/assets')
        ->assertInertia(fn (Assert $page) => $page
            ->where('assets.total', 1)
            ->where('assets.data.0.name', 'Acme PC')
            ->where('customers', [['id' => $this->acme->id, 'code' => 'ACME', 'name' => 'Acme']]));
    $this->actingAs($this->client)->get("/assets/{$this->betaAsset->ulid}")->assertForbidden();
    $this->actingAs($this->client)->get("/assets/{$this->ownAsset->ulid}")->assertForbidden();

    $this->actingAs($this->client)->get('/tickets?status=all')
        ->assertInertia(fn (Assert $page) => $page->where('tickets.total', 1)->where('tickets.data.0.title', 'Acme job'));
    $this->actingAs($this->client)->get("/tickets/{$betaTicket->ulid}")->assertForbidden();
});

it('keeps a customer account out of the staff pages', function () {
    // contracts: customer_it sees its own customer's contracts (scope customer).
    foreach (['/users', '/roles', '/customers', '/asset-categories', '/holidays', '/assets/export'] as $url) {
        $this->actingAs($this->client)->get($url)->assertForbidden();
    }

    $this->actingAs($this->client)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => expect(collect($page->toArray()['props']['navigation'])->pluck('title')->all())
            ->toBe(['หน้าหลัก', 'ใบงาน', 'ทรัพย์สิน', 'สัญญา MA', 'รายงาน', 'สรุปรายโครงการ', 'ผลประเมินความพึงพอใจ']));
});

it('lets a customer account open a ticket for its own asset, and tells the dispatchers', function () {
    $this->actingAs($this->client)->get("/tickets/create?asset={$this->acmeAsset->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('customerAccount', true)->where('contracts', [])->where('assignees', []));

    $this->actingAs($this->client)->post('/tickets', [
        'asset_id' => $this->acmeAsset->id, 'title' => 'จอไม่ติด', 'priority' => 'medium',
        // ignored for customer accounts
        'customer_id' => $this->beta->id, 'source' => 'phone', 'assignee_id' => $this->helpdesk->id,
    ])->assertSessionHasNoErrors();

    $ticket = Ticket::first();
    expect($ticket->only(['customer_id', 'source', 'assignee_id', 'reported_by', 'contact_name']))
        ->toBe(['customer_id' => $this->acme->id, 'source' => 'portal', 'assignee_id' => null, 'reported_by' => $this->client->id, 'contact_name' => 'Acme IT'])
        ->and($ticket->contract_id)->not->toBeNull()
        ->and($ticket->resolve_due_at->format('Y-m-d H:i'))->toBe('2026-06-15 13:00');

    Notification::assertSentTo($this->helpdesk, TicketNotification::class, fn ($n) => $n->event === 'opened');
    Notification::assertNotSentTo($this->client, TicketNotification::class);

    $this->actingAs($this->client)->post('/tickets', [
        'asset_id' => $this->betaAsset->id, 'title' => 'x', 'priority' => 'low',
    ])->assertSessionHasErrors('asset_id');
});

it('hides internal notes from a customer account and keeps its comments public', function () {
    $ticket = openTicket($this->helpdesk, ['customer_id' => $this->acme->id, 'asset_id' => $this->acmeAsset->id], warrantyChecked: false);
    $this->actingAs($this->helpdesk)->post("/tickets/{$ticket->ulid}/comments", ['body' => 'ลูกค้าค้างชำระ', 'is_internal' => true]);
    $this->actingAs($this->helpdesk)->post("/tickets/{$ticket->ulid}/comments", ['body' => 'ช่างจะเข้าวันนี้']);
    $this->actingAs($this->client)->post("/tickets/{$ticket->ulid}/comments", ['body' => 'รับทราบ', 'is_internal' => true]);

    $this->actingAs($this->client)->get("/tickets/{$ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('events', fn ($events) => collect($events)->pluck('body')->filter()->values()->all() === ['ช่างจะเข้าวันนี้', 'รับทราบ'])
            ->where('can.internalNotes', false)
            ->where('assignees', null));

    expect($ticket->events()->where('body', 'รับทราบ')->value('is_internal'))->toBeFalse();
});

it('lets the customer confirm or reject the fix of its own ticket only', function () {
    $tech = userWithRole('technician');
    $ticket = openTicket($this->client, ['customer_id' => $this->acme->id, 'asset_id' => $this->acmeAsset->id]);
    $this->actingAs($this->helpdesk)->post("/tickets/{$ticket->ulid}/assign", ['assignee_id' => $tech->id]);
    $this->actingAs($tech)->post("/tickets/{$ticket->ulid}/move", ['action' => 'start']);

    $this->actingAs($this->client)->post("/tickets/{$ticket->ulid}/move", ['action' => 'resolve'])->assertForbidden();
    $this->actingAs($this->client)->post("/tickets/{$ticket->ulid}/move", ['action' => 'cancel', 'comment' => 'x'])->assertForbidden();

    $this->actingAs($tech)->post("/tickets/{$ticket->ulid}/move", ['action' => 'resolve'])->assertSessionHasNoErrors();
    // the reporting customer is asked to confirm
    Notification::assertSentTo($this->client, TicketNotification::class, fn ($n) => $n->event === 'resolved');

    $this->actingAs($this->client)->get("/tickets/{$ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('actions', ['approve', 'reject']));
    $this->actingAs($this->client)->post("/tickets/{$ticket->ulid}/move", ['action' => 'approve'])->assertSessionHasNoErrors();
    expect($ticket->fresh()->status)->toBe(Ticket::STATUS_CLOSED);

    // another customer's account cannot touch it
    $betaClient = userWithRole('customer_it', ['customer_id' => $this->beta->id]);
    $this->actingAs($betaClient)->get("/tickets/{$ticket->ulid}")->assertForbidden();
});
