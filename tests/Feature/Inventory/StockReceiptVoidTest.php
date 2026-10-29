<?php

use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Models\Activity;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A receipt entered by mistake is voided: a "void" row takes the stock back out and points at it,
 * the receipt itself stays in the ledger.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->cable = createPart(['code' => 'CAT6', 'name' => 'LAN cable']);
    $this->ssd = createTrackedPart(['code' => 'SSD', 'name' => 'SSD 1TB']);
    $this->receive = fn (array $data) => $this->actingAs($this->admin)->post('/stock-receipts', $data)->assertSessionHasNoErrors();
    $this->void = fn (StockMovement $receipt, string $reason = 'คีย์จำนวนผิด') => $this->actingAs($this->admin)
        ->post("/stock-movements/{$receipt->id}/void", ['reason' => $reason]);
    $this->lastReceipt = fn ($part) => StockMovement::where('part_id', $part->id)->where('type', 'receive')->latest('id')->first();
});

it('voids a receipt: the stock comes back out, and both rows stay in the ledger', function () {
    ($this->receive)(['part_id' => $this->cable->id, 'quantity' => 50, 'reference' => 'INV-1']);
    $receipt = ($this->lastReceipt)($this->cable);

    ($this->void)($receipt, '')->assertSessionHasErrors('reason');
    ($this->void)($receipt)->assertSessionHasNoErrors();

    $void = StockMovement::where('voids_id', $receipt->id)->sole();
    expect($this->cable->fresh()->qty_on_hand)->toBe(0)
        ->and($void->only(['type', 'quantity', 'balance_after', 'reference', 'note']))->toBe([
            'type' => 'void', 'quantity' => -50, 'balance_after' => 0, 'reference' => 'INV-1', 'note' => 'คีย์จำนวนผิด',
        ])
        ->and($receipt->fresh()->quantity)->toBe(50)
        ->and(Activity::where('event', 'stock_receipt_voided')->exists())->toBeTrue();

    // Only once; the ledger marks both.
    ($this->void)($receipt)->assertSessionHasErrors('void');
    $this->actingAs($this->admin)->get('/stock-movements')->assertInertia(fn (Assert $page) => $page
        ->where('movements.data', fn ($rows) => collect($rows)->firstWhere('id', $receipt->id)['voided'] === true
            && collect($rows)->firstWhere('id', $receipt->id)['voidable'] === false
            && collect($rows)->firstWhere('id', $void->id)['voids_id'] === $receipt->id));
});

it('does not void what was already taken out, nor anything but a receipt', function () {
    ($this->receive)(['part_id' => $this->cable->id, 'quantity' => 10]);
    $receipt = ($this->lastReceipt)($this->cable);
    $this->actingAs($this->admin)->post("/parts/{$this->cable->id}/movements", ['type' => 'issue', 'quantity' => 4])->assertSessionHasNoErrors();

    ($this->void)($receipt)->assertSessionHasErrors('void');
    expect($this->cable->fresh()->qty_on_hand)->toBe(6);

    $issue = StockMovement::where('part_id', $this->cable->id)->where('type', 'issue')->sole();
    ($this->void)($issue)->assertSessionHasErrors('void');
});

it('voids the pieces of a tracked part, freeing their serials, only while all are in stock', function () {
    ($this->receive)(['part_id' => $this->ssd->id, 'quantity' => 2, 'serials' => ['S1', 'S2']]);
    $receipt = ($this->lastReceipt)($this->ssd);

    // One piece is out: refused.
    PartUnit::where('serial_number', 'S1')->update(['status' => PartUnit::STATUS_ISSUED]);
    ($this->void)($receipt)->assertSessionHasErrors('void');
    PartUnit::where('serial_number', 'S1')->update(['status' => PartUnit::STATUS_IN_STOCK]);

    ($this->void)($receipt)->assertSessionHasNoErrors();
    expect($this->ssd->fresh()->qty_on_hand)->toBe(0)
        ->and(PartUnit::where('part_id', $this->ssd->id)->count())->toBe(0)
        ->and(PartUnit::withTrashed()->where('part_id', $this->ssd->id)->pluck('status')->unique()->all())->toBe(['removed']);

    // The serials can be received again, typed right this time.
    ($this->receive)(['part_id' => $this->ssd->id, 'quantity' => 2, 'serials' => ['S1', 'S2']]);
    expect($this->ssd->fresh()->qty_on_hand)->toBe(2);
});

it('leaves a purchase request\'s receipt to the request, and needs the right to enter stock', function () {
    ($this->receive)(['part_id' => $this->cable->id, 'quantity' => 5]);
    $receipt = ($this->lastReceipt)($this->cable);

    $this->actingAs(userWithRole('technician'))->post("/stock-movements/{$receipt->id}/void", ['reason' => 'x'])->assertForbidden();

    $buyer = userWithRole('purchasing');
    $this->actingAs($buyer)->post('/purchase-requests', [
        'purpose' => 'delivery', 'for_internal' => true, 'item_name' => 'LAN cable', 'quantity' => 5, 'unit' => 'pcs', 'unit_price' => '10', 'reason' => 'stock', 'item_kind' => 'part',
        'links' => ['https://shop.example.com/cable'], 'needed_by' => now()->addWeek()->toDateString(),
    ])->assertSessionHasNoErrors();
    PurchaseReceipt::create([
        'purchase_request_id' => PurchaseRequest::latest('id')->value('id'), 'quantity' => 5, 'received_at' => now(), 'stock_movement_id' => $receipt->id,
    ]);

    ($this->void)($receipt)->assertSessionHasErrors('void');
    expect($this->cable->fresh()->qty_on_hand)->toBe(5);
});

it('does not void another company\'s receipt', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, function () {
        $part = createPart(['code' => 'X1'], 5);

        return StockMovement::where('part_id', $part->id)->sole();
    });

    $this->actingAs($this->admin)->post("/stock-movements/{$theirs->id}/void", ['reason' => 'x'])->assertNotFound();
    expect(asTenant($other, fn () => StockMovement::where('voids_id', $theirs->id)->exists()))->toBeFalse();
});
