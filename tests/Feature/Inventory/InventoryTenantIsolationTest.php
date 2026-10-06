<?php

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Service\Actions\AssignTicket;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->mine = createPart(['code' => 'RAM', 'name' => 'My memory'], stock: 4);

    $this->other = createTenant('other');
    $this->theirs = asTenant($this->other, fn () => createPart(['code' => 'RAM', 'name' => 'Their memory'], stock: 9));
});

it('lists only parts and movements of the own tenant (same codes allowed)', function () {
    $this->actingAs($this->admin)->get('/parts')
        ->assertInertia(fn (Assert $page) => $page->where('parts.total', 1)->where('parts.data.0.name', 'My memory'));

    $this->actingAs($this->admin)->get('/stock-movements')
        ->assertInertia(fn (Assert $page) => $page->where('movements.total', 1)->where('movements.data.0.part.name', 'My memory'));

    // narrowing the ledger to a part of another tenant shows nothing about it
    $this->actingAs($this->admin)->get("/stock-movements?part_id={$this->theirs->id}")
        ->assertInertia(fn (Assert $page) => $page->where('movements.total', 0)->where('part', null));
});

it('returns 404 for parts of another tenant and leaves their stock alone', function () {
    $this->actingAs($this->admin)->get("/parts/{$this->theirs->id}")->assertNotFound();
    $this->actingAs($this->admin)->get("/parts/{$this->theirs->id}/edit")->assertNotFound();
    $this->actingAs($this->admin)->put("/parts/{$this->theirs->id}", ['code' => 'X', 'name' => 'X', 'unit' => 'pcs'])->assertNotFound();
    $this->actingAs($this->admin)->delete("/parts/{$this->theirs->id}")->assertNotFound();
    $this->actingAs($this->admin)->post("/parts/{$this->theirs->id}/movements", ['type' => 'issue', 'quantity' => 1])->assertNotFound();

    asTenant($this->other, fn () => expect(Part::first()->only(['name', 'qty_on_hand']))->toBe(['name' => 'Their memory', 'qty_on_hand' => 9]));
});

it('cannot book a part of another tenant on a ticket, nor a part on their ticket', function () {
    $tech = userWithRole('technician');
    $ticket = openTicket($this->admin);
    app(AssignTicket::class)->handle($ticket, $tech->id, $this->admin);

    $this->actingAs($tech)->post("/tickets/{$ticket->ulid}/parts", ['part_id' => $this->theirs->id, 'quantity' => 1])
        ->assertSessionHasErrors('part_id');

    $theirTicket = asTenant($this->other, fn () => openTicket(userWithRole('helpdesk')));
    $this->actingAs($tech)->post("/tickets/{$theirTicket->ulid}/parts", ['part_id' => $this->mine->id, 'quantity' => 1])->assertNotFound();

    expect($this->mine->fresh()->qty_on_hand)->toBe(4);
    asTenant($this->other, fn () => expect(Part::first()->qty_on_hand)->toBe(9));
});

it('hides other tenants from queries', function () {
    expect(Part::pluck('name')->all())->toBe(['My memory'])
        ->and(StockMovement::pluck('balance_after')->all())->toBe([4]);

    // even an update that names their row changes nothing
    expect(Part::whereKey($this->theirs->id)->update(['qty_on_hand' => 0]))->toBe(0);
});
