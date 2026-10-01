<?php

use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Notifications\TicketNotification;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-06-15 09:00');

    $this->helpdesk = userWithRole('helpdesk');
    $this->north = Branch::create(['code' => 'N', 'name' => 'North']);
    $this->south = Branch::create(['code' => 'S', 'name' => 'South']);
    $this->tech = userWithRole('technician', ['name' => 'Tech One', 'branch_id' => $this->north->id]);
    $this->otherTech = userWithRole('technician', ['name' => 'Tech Two', 'branch_id' => $this->north->id]);

    $this->acme = createCustomer(['name' => 'Acme']);
    $asset = createAsset(createAssetCategory(), ['customer_id' => $this->acme->id, 'branch_id' => $this->north->id]);
    $this->ticket = openTicket($this->helpdesk, ['customer_id' => $this->acme->id, 'asset_id' => $asset->id]);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);
    $this->roller = createPart(['code' => 'ROLLER'], stock: 5);
});

it('lets a technician (parts.issue own) take parts only for their own tickets', function () {
    // the colleague may see and update every ticket, but issues parts only to their own
    setRoleScope('technician', 'all', ['tickets.view', 'tickets.update']);

    $this->actingAs($this->otherTech)->get("/tickets/{$this->ticket->ulid}")
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('parts.canIssue', false));
    $this->actingAs($this->otherTech)->post("/tickets/{$this->ticket->ulid}/parts", ['part_id' => $this->roller->id, 'quantity' => 1])
        ->assertForbidden();

    $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('parts.canIssue', true));
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ulid}/parts", ['part_id' => $this->roller->id, 'quantity' => 1])
        ->assertSessionHasNoErrors();
});

it('needs parts.issue to take parts for a ticket', function () {
    $user = userWithRole('user');
    $mine = openTicket($user, ['title' => 'My PC']);
    grantTo('user', ['tickets.update'], 'own');

    $this->actingAs($user)->post("/tickets/{$mine->ulid}/parts", ['part_id' => $this->roller->id, 'quantity' => 1])->assertForbidden();
});

it('only tells dispatchers about tickets within their scope', function () {
    $southDispatcher = userWithRole('helpdesk', ['branch_id' => $this->south->id]);
    $northDispatcher = userWithRole('helpdesk', ['branch_id' => $this->north->id]);
    setRoleScope('helpdesk', 'branch', ['tickets.assign']);
    $client = userWithRole('customer_it', ['customer_id' => $this->acme->id]);
    $asset = createAsset(createAssetCategory(), ['customer_id' => $this->acme->id, 'branch_id' => $this->north->id]);

    $this->actingAs($client)->post('/tickets', ['asset_id' => $asset->id, 'title' => 'จอไม่ติด', 'priority' => 'medium'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($northDispatcher, TicketNotification::class, fn ($n) => $n->event === 'opened');
    Notification::assertNotSentTo($southDispatcher, TicketNotification::class);
});

it('lets a customer account see only its customer\'s tickets', function () {
    $client = userWithRole('customer_it', ['customer_id' => $this->acme->id]);
    $other = openTicket($this->helpdesk, ['title' => 'Someone else', 'customer_id' => createCustomer()->id]);

    $this->actingAs($client)->get('/tickets?status=all')->assertInertia(fn (Assert $page) => $page->where('tickets.total', 1));
    $this->actingAs($client)->get("/tickets/{$this->ticket->ulid}")->assertOk();
    $this->actingAs($client)->get("/tickets/{$other->ulid}")->assertForbidden();
});
