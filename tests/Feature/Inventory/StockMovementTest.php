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

it('rejects a return or an unknown type entered by hand', function () {
    ($this->move)(['type' => 'return', 'quantity' => 1])->assertForbidden();
    ($this->move)(['type' => 'steal', 'quantity' => 1])->assertForbidden();
    expect(StockMovement::count())->toBe(0);
});

it('checks the permission of each movement type', function () {
    ($this->move)(['type' => 'receive', 'quantity' => 5]);
    $technician = userWithRole('technician');
    $helpdesk = userWithRole('helpdesk');

    // a technician may take parts, but receiving and counting is office work
    ($this->move)(['type' => 'issue', 'quantity' => 1], $technician)->assertSessionHasNoErrors();
    ($this->move)(['type' => 'receive', 'quantity' => 1], $technician)->assertForbidden();
    ($this->move)(['type' => 'adjust', 'quantity' => 1, 'note' => 'x'], $technician)->assertForbidden();
    ($this->move)(['type' => 'issue', 'quantity' => 1], $helpdesk)->assertForbidden();
    ($this->move)(['type' => 'issue', 'quantity' => 1], userWithRole('user'))->assertForbidden();

    $this->actingAs($technician)->get("/parts/{$this->part->id}")
        ->assertInertia(fn (Assert $page) => $page->where('movementTypes', ['issue'])->where('part.qty_on_hand', 4));
    $this->actingAs($helpdesk)->get("/parts/{$this->part->id}")
        ->assertInertia(fn (Assert $page) => $page->where('movementTypes', [])->where('movements.total', 2));
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
