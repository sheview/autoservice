<?php

use App\Modules\Inventory\Models\StockMovement;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Store keeper']);
    $this->part = createPart(['code' => 'RAM', 'name' => 'Memory', 'min_qty' => 2]);
    $this->move = fn (array $data, $user = null) => $this->actingAs($user ?? $this->admin)
        ->post("/parts/{$this->part->id}/movements", $data);
});

it('receives stock, remembers the latest cost and writes the ledger', function () {
    ($this->move)(['type' => 'receive', 'quantity' => 10, 'unit_cost' => '950', 'reference' => 'DN-001'])->assertSessionHasNoErrors();
    ($this->move)(['type' => 'receive', 'quantity' => 5, 'unit_cost' => '1000.25'])->assertSessionHasNoErrors();

    expect($this->part->fresh()->only(['qty_on_hand', 'unit_cost']))->toBe(['qty_on_hand' => 15, 'unit_cost' => 100025]);

    $rows = StockMovement::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->only(['type', 'quantity', 'balance_after', 'unit_cost', 'reference', 'user_id', 'user_name', 'tenant_id']))->toBe([
            'type' => 'receive', 'quantity' => 10, 'balance_after' => 10, 'unit_cost' => 95000, 'reference' => 'DN-001',
            'user_id' => $this->admin->id, 'user_name' => 'Store keeper', 'tenant_id' => $this->tenant->id,
        ])
        ->and($rows[1]->balance_after)->toBe(15);
});

it('issues stock but never below zero', function () {
    ($this->move)(['type' => 'receive', 'quantity' => 3]);

    ($this->move)(['type' => 'issue', 'quantity' => 2, 'note' => 'ใช้ภายในสำนักงาน'])->assertSessionHasNoErrors();
    ($this->move)(['type' => 'issue', 'quantity' => 2])->assertSessionHasErrors('quantity');
    ($this->move)(['type' => 'issue', 'quantity' => 0])->assertSessionHasErrors('quantity');

    expect($this->part->fresh()->qty_on_hand)->toBe(1)
        ->and(StockMovement::where('type', 'issue')->get(['quantity', 'balance_after'])->toArray())
        ->toBe([['quantity' => -2, 'balance_after' => 1]]);
});

it('adjusts stock to the counted quantity and needs a reason', function () {
    ($this->move)(['type' => 'receive', 'quantity' => 8]);

    ($this->move)(['type' => 'adjust', 'quantity' => 5])->assertSessionHasErrors('note');
    ($this->move)(['type' => 'adjust', 'quantity' => 8, 'note' => 'นับสต็อก'])->assertSessionHasErrors('quantity');
    ($this->move)(['type' => 'adjust', 'quantity' => 5, 'note' => 'นับสต็อกสิ้นเดือน'])->assertSessionHasNoErrors();
    ($this->move)(['type' => 'adjust', 'quantity' => 0, 'note' => 'ของเสียหายทั้งหมด'])->assertSessionHasNoErrors();

    expect($this->part->fresh()->qty_on_hand)->toBe(0)
        ->and(StockMovement::where('type', 'adjust')->orderBy('id')->pluck('quantity')->all())->toBe([-3, -5]);
});

it('lends parts and puts them in as spares, and takes them back', function () {
    ($this->move)(['type' => 'receive', 'quantity' => 5]);

    ($this->move)(['type' => 'loan', 'quantity' => 2, 'note' => 'ลูกค้ายืมทดสอบ'])->assertSessionHasNoErrors();
    ($this->move)(['type' => 'spare', 'quantity' => 1])->assertSessionHasNoErrors();
    ($this->move)(['type' => 'loan', 'quantity' => 3])->assertSessionHasErrors('quantity');
    ($this->move)(['type' => 'return', 'quantity' => 2, 'note' => 'ลูกค้าคืน'])->assertSessionHasNoErrors();

    expect($this->part->fresh()->qty_on_hand)->toBe(4)
        ->and(StockMovement::orderBy('id')->get(['type', 'quantity', 'balance_after'])->toArray())->toBe([
            ['type' => 'receive', 'quantity' => 5, 'balance_after' => 5],
            ['type' => 'loan', 'quantity' => -2, 'balance_after' => 3],
            ['type' => 'spare', 'quantity' => -1, 'balance_after' => 2],
            ['type' => 'return', 'quantity' => 2, 'balance_after' => 4],
        ]);

    $this->actingAs($this->admin)->get('/stock-movements?type=loan')
        ->assertInertia(fn (Assert $page) => $page->where('movements.total', 1)
            ->where('types', ['receive', 'issue', 'loan', 'spare', 'return', 'adjust']));
});

it('shows the history of a part newest first by date', function () {
    // entered late for an earlier day (as the demo seeder does): the date decides, not the row id
    $this->travelTo('2026-06-10 09:00');
    ($this->move)(['type' => 'receive', 'quantity' => 10, 'reference' => 'second']);
    $this->travelTo('2026-06-01 09:00');
    ($this->move)(['type' => 'receive', 'quantity' => 5, 'reference' => 'first']);
    $this->travelTo('2026-06-20 09:00');
    ($this->move)(['type' => 'issue', 'quantity' => 1, 'reference' => 'third']);

    $this->actingAs($this->admin)->get("/parts/{$this->part->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('movements.data', fn ($rows) => collect($rows)->pluck('reference')->all() === ['third', 'second', 'first']));
});

it('rejects an unknown type', function () {
    ($this->move)(['type' => 'steal', 'quantity' => 1])->assertForbidden();
    expect(StockMovement::count())->toBe(0);
});

it('checks the permission of each movement type', function () {
    ($this->move)(['type' => 'receive', 'quantity' => 5]);
    $technician = userWithRole('technician');
    $helpdesk = userWithRole('helpdesk');

    // writing the ledger directly is stock-movements.create (helpdesk); technicians take parts
    // on a ticket or an issue/loan form instead
    foreach (['issue', 'receive', 'loan', 'return'] as $type) {
        ($this->move)(['type' => $type, 'quantity' => 1], $technician)->assertForbidden();
    }
    ($this->move)(['type' => 'adjust', 'quantity' => 1, 'note' => 'x'], $technician)->assertForbidden();
    ($this->move)(['type' => 'issue', 'quantity' => 1], $helpdesk)->assertSessionHasNoErrors();
    ($this->move)(['type' => 'receive', 'quantity' => 2], $helpdesk)->assertSessionHasNoErrors();
    ($this->move)(['type' => 'issue', 'quantity' => 1], userWithRole('user'))->assertForbidden();

    $this->actingAs($technician)->get("/parts/{$this->part->id}")
        ->assertInertia(fn (Assert $page) => $page->where('movementTypes', [])->where('part.qty_on_hand', 6));
    $this->actingAs($helpdesk)->get("/parts/{$this->part->id}")
        ->assertInertia(fn (Assert $page) => $page->where('movementTypes', ['receive', 'issue', 'loan', 'spare', 'return', 'adjust'])->where('movements.total', 3));
});

it('lists the ledger with search, filters and sort', function () {
    $other = createPart(['code' => 'SSD', 'name' => 'Solid state drive']);
    ($this->move)(['type' => 'receive', 'quantity' => 10, 'reference' => 'DN-001']);
    ($this->move)(['type' => 'issue', 'quantity' => 4]);
    $this->actingAs($this->admin)->post("/parts/{$other->id}/movements", ['type' => 'receive', 'quantity' => 2, 'reference' => 'DN-002']);

    $get = fn (string $query) => $this->actingAs($this->admin)->get("/stock-movements?{$query}");

    $get('')->assertInertia(fn (Assert $page) => $page->component('Inventory/Movements/Index')
        ->where('movements.total', 3)
        // newest first
        ->where('movements.data.0.part.code', 'SSD')
        ->where('movements.data.1.quantity', -4)
        ->where('part', null));
    $get('search=dn-001')->assertInertia(fn (Assert $page) => $page->where('movements.total', 1));
    $get('search=solid')->assertInertia(fn (Assert $page) => $page->where('movements.total', 1));
    $get('type=issue')->assertInertia(fn (Assert $page) => $page->where('movements.total', 1)->where('movements.data.0.type', 'issue'));
    $get("part_id={$this->part->id}")->assertInertia(fn (Assert $page) => $page->where('movements.total', 2)->where('part.code', 'RAM'));
    $get('sort=quantity&direction=asc')->assertInertia(fn (Assert $page) => $page->where('movements.data.0.quantity', -4));

    $this->actingAs(userWithRole('user'))->get('/stock-movements')->assertForbidden();
});

it('shows a technician (scope own) only the ledger rows they entered', function () {
    ($this->move)(['type' => 'receive', 'quantity' => 10]);
    $technician = userWithRole('technician');
    grantTo('technician', ['stock-movements.create']);
    ($this->move)(['type' => 'issue', 'quantity' => 1, 'reference' => 'mine'], $technician)->assertSessionHasNoErrors();

    $this->actingAs($technician)->get('/stock-movements')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('movements.total', 1)->where('movements.data.0.reference', 'mine'));
    $this->actingAs($technician)->get("/parts/{$this->part->id}")
        ->assertInertia(fn (Assert $page) => $page->where('movements.total', 1));
    $this->actingAs(userWithRole('helpdesk'))->get('/stock-movements')
        ->assertInertia(fn (Assert $page) => $page->where('movements.total', 2));
});
