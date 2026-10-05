<?php

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockMovement;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Receiving parts followed by serial number: straight into stock, and from a purchase request
 * (now, or registered later), one piece per serial, as many serials as units.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->buyer = userWithRole('purchasing', ['name' => 'Buyer Ploy']);
    $this->ssd = createTrackedPart(['code' => 'SSD', 'name' => 'SSD 1TB']);
    $this->cable = createPart(['code' => 'CAT6', 'name' => 'LAN cable']);
    $this->receive = fn (array $data, $user = null) => $this->actingAs($user ?? $this->buyer)->post('/stock-receipts', $data);

    // A purchase request for parts, approved.
    $this->approved = function (array $data = []) {
        $this->actingAs($this->buyer)->post('/purchase-requests', [
            'item_name' => 'SSD 1TB', 'quantity' => 3, 'unit' => 'pcs', 'unit_price' => '2500', 'reason' => 'stock', 'item_kind' => 'part',
            'links' => ['https://shop.example.com/ssd'], 'needed_by' => now()->addWeek()->toDateString(), ...$data,
        ])->assertSessionHasNoErrors();
        $pr = PurchaseRequest::latest('id')->first();
        $this->actingAs($this->admin)->post("/purchase-requests/{$pr->ulid}/move", ['action' => 'approve'])->assertSessionHasNoErrors();

        return $pr->fresh();
    };
});

it('receives straight into stock: serials for a tracked part, a quantity for the others', function () {
    $this->actingAs($this->buyer)->get("/stock-receipts/create?part={$this->ssd->id}")
        ->assertInertia(fn (Assert $page) => $page->component('Inventory/Receive')->where('part.track_serial', true));

    // As many serials as units.
    ($this->receive)(['part_id' => $this->ssd->id, 'quantity' => 3, 'serials' => ['A1', 'A2']])->assertSessionHasErrors('serials');
    ($this->receive)(['part_id' => $this->ssd->id, 'quantity' => 2, 'serials' => ['A1', 'A2'], 'unit_cost' => '2400', 'supplier' => 'IT City',
        'reference' => 'INV-9', 'warranty_until' => '2029-12-31', 'received_on' => '2026-10-01'])
        ->assertSessionHasNoErrors()->assertRedirect("/parts/{$this->ssd->id}");

    expect($this->ssd->fresh()->qty_on_hand)->toBe(2)
        ->and(PartUnit::where('part_id', $this->ssd->id)->orderBy('id')->get()->map->only(['serial_number', 'supplier', 'unit_cost', 'source'])->all())->toBe([
            ['serial_number' => 'A1', 'supplier' => 'IT City', 'unit_cost' => 240000, 'source' => 'receive'],
            ['serial_number' => 'A2', 'supplier' => 'IT City', 'unit_cost' => 240000, 'source' => 'receive'],
        ])
        ->and(PartUnit::first()->received_on->toDateString())->toBe('2026-10-01')
        ->and(StockMovement::latest('id')->first()->reference)->toBe('INV-9');

    ($this->receive)(['part_id' => $this->cable->id, 'quantity' => 50])->assertSessionHasNoErrors();
    expect($this->cable->fresh()->qty_on_hand)->toBe(50)->and(PartUnit::where('part_id', $this->cable->id)->count())->toBe(0);

    // Technicians do not receive goods.
    ($this->receive)(['part_id' => $this->cable->id, 'quantity' => 1], userWithRole('technician'))->assertForbidden();
});

it('receives a tracked part from a purchase request with one serial per unit, in several deliveries', function () {
    $pr = ($this->approved)();
    $post = fn (array $data) => $this->actingAs($this->buyer)->post("/purchase-requests/{$pr->ulid}/receipts", $data + ['part_id' => $this->ssd->id]);

    // Too few serials: nothing is recorded at all.
    $post(['quantity' => 2, 'serials' => 'S-1'])->assertSessionHasErrors('serials');
    expect(PurchaseReceipt::count())->toBe(0)->and($pr->fresh()->qty_received)->toBe(0);

    $post(['quantity' => 2, 'serials' => "S-1\nS-2", 'brand' => 'Samsung', 'model' => '990 Pro', 'unit_price' => '2390'])->assertSessionHasNoErrors();
    $post(['quantity' => 1, 'serials' => 'S-1'])->assertSessionHasErrors('serials'); // already in stock
    $post(['quantity' => 1, 'serials' => 'S-3'])->assertSessionHasNoErrors();

    $receipt = PurchaseReceipt::orderBy('id')->first();
    expect($this->ssd->fresh()->qty_on_hand)->toBe(3)
        ->and($pr->fresh()->only(['qty_received', 'qty_registered']))->toBe(['qty_received' => 3, 'qty_registered' => 3])
        ->and(PartUnit::where('purchase_receipt_id', $receipt->id)->pluck('serial_number')->all())->toBe(['S-1', 'S-2'])
        ->and(PartUnit::where('serial_number', 'S-1')->first()->only(['source', 'unit_cost']))->toBe(['source' => 'purchase', 'unit_cost' => 239000])
        ->and(StockMovement::where('part_id', $this->ssd->id)->pluck('reference')->unique()->all())->toBe([$pr->pr_no]);
});

it('makes a new part tracked by serial from a delivery when asked', function () {
    $pr = ($this->approved)(['item_name' => 'PSU 650W']);

    $this->actingAs($this->admin)->post("/purchase-requests/{$pr->ulid}/receipts", ['quantity' => 2, 'serials' => "P-1\nP-2", 'part_code' => 'PSU650', 'track_serial' => true])
        ->assertSessionHasNoErrors();

    $part = Part::where('code', 'PSU650')->sole();
    expect($part->track_serial)->toBeTrue()->and($part->qty_on_hand)->toBe(2)
        ->and(PartUnit::where('part_id', $part->id)->count())->toBe(2);
});

it('registers a received delivery into a tracked part later, refusing a wrong number of serials', function () {
    $pr = ($this->approved)();
    // A delivery recorded before it was put into the system (as older requests were).
    $receipt = $pr->receipts()->create(['quantity' => 2, 'serials' => ['R-1'], 'received_by_name' => 'Buyer Ploy', 'received_at' => now()]);
    $pr->forceFill(['qty_received' => 2, 'status' => 'partially_received'])->save();
    $register = fn (array $data) => $this->actingAs($this->buyer)->post("/purchase-requests/{$pr->ulid}/receipts/{$receipt->id}/register", ['as' => 'part'] + $data);

    $register(['part_id' => $this->ssd->id])->assertSessionHasErrors('serials');
    expect($this->ssd->fresh()->qty_on_hand)->toBe(0)->and($receipt->fresh()->registered_at)->toBeNull();

    // The same delivery is fine as stock of a part counted by quantity.
    $register(['part_id' => $this->cable->id])->assertSessionHasNoErrors();
    expect($this->cable->fresh()->qty_on_hand)->toBe(2);
});

it('hands a tracked part bought for a job to whoever asked, with the very pieces of the delivery', function () {
    $desk = userWithRole('helpdesk');
    $ticket = openTicket($desk);
    // Something already in stock that must not be the one handed over.
    $this->actingAs($this->buyer)->post('/stock-receipts', ['part_id' => $this->ssd->id, 'quantity' => 1, 'serials' => ['OLD-1']])->assertSessionHasNoErrors();

    $this->actingAs($desk)->post('/checkout-requests', [
        'borrower_user_id' => $desk->id, 'purpose' => 'Disk for job', 'submit' => true, 'ticket_id' => $ticket->id,
        'then_purchase' => 'SSD 1TB', 'items' => [['item_type' => 'part', 'part_id' => $this->cable->id, 'qty' => 1]],
    ]);
    $draft = CheckoutRequest::latest('id')->first();
    $pr = ($this->approved)(['checkout_request_id' => $draft->id, 'quantity' => 2]);
    $this->actingAs($this->buyer)->post("/purchase-requests/{$pr->ulid}/receipts", ['quantity' => 2, 'serials' => "NEW-1\nNEW-2", 'part_id' => $this->ssd->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->buyer)->post("/purchase-requests/{$pr->ulid}/hand-out")->assertSessionHasNoErrors();

    expect(PartUnit::where('status', 'issued')->pluck('serial_number')->sort()->values()->all())->toBe(['NEW-1', 'NEW-2'])
        ->and(PartUnit::where('serial_number', 'OLD-1')->value('status'))->toBe('in_stock')
        ->and($this->ssd->fresh()->qty_on_hand)->toBe(1);
});
