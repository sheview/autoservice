<?php

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCategory;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Models\Activity;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Store keeper']);
    $this->part = createTrackedPart(['code' => 'SSD', 'name' => 'SSD 512GB']);
    $this->move = fn (array $data, $user = null, $part = null) => $this->actingAs($user ?? $this->admin)
        ->post('/parts/'.($part ?? $this->part)->id.'/movements', $data);
    $this->unitIds = fn (array $serials) => PartUnit::whereIn('serial_number', $serials)->orderBy('id')->pluck('id')->all();
    $this->inStock = fn () => PartUnit::where('part_id', $this->part->id)->where('status', 'in_stock')->count();
});

it('receives one piece per serial and counts the stock from them', function () {
    ($this->move)(['type' => 'receive', 'serials' => ["SN-1\nSN-2", ' SN-3 '], 'unit_cost' => '1200', 'supplier' => 'ABC IT', 'warranty_until' => '2029-01-31'])
        ->assertSessionHasNoErrors();

    $part = $this->part->fresh();
    expect($part->qty_on_hand)->toBe(3)->and(($this->inStock)())->toBe(3)
        ->and(PartUnit::orderBy('id')->pluck('serial_number')->all())->toBe(['SN-1', 'SN-2', 'SN-3'])
        ->and(PartUnit::first()->only(['unit_cost', 'supplier', 'source']))->toBe(['unit_cost' => 120000, 'supplier' => 'ABC IT', 'source' => 'receive'])
        ->and(PartUnit::first()->warranty_until->toDateString())->toBe('2029-01-31');

    $movement = StockMovement::sole();
    expect($movement->only(['type', 'quantity', 'balance_after']))->toBe(['type' => 'receive', 'quantity' => 3, 'balance_after' => 3])
        ->and(PartUnitEvent::where('stock_movement_id', $movement->id)->count())->toBe(3);
});

it('refuses a serial the part already has, or the same one twice, and saves nothing', function () {
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1']]);

    ($this->move)(['type' => 'receive', 'serials' => ['sn-1', 'SN-9']])->assertSessionHasErrors('serials');
    ($this->move)(['type' => 'receive', 'serials' => ['SN-5', 'sn-5']])->assertSessionHasErrors('serials');
    // A tracked part is not received by a quantity.
    ($this->move)(['type' => 'receive', 'quantity' => 2])->assertSessionHasErrors('serials');

    expect($this->part->fresh()->qty_on_hand)->toBe(1)->and(PartUnit::count())->toBe(1);
});

it('lets another company, or another part, use the same serial (the latter with a warning)', function () {
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1']]);

    $other = createTenant('other');
    asTenant($other, fn () => createTrackedPart(['code' => 'SSD'], ['SN-1']));

    $hdd = createTrackedPart(['code' => 'HDD']);
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1']], part: $hdd)->assertSessionHasNoErrors()
        ->assertSessionHas('warning', fn (string $text) => str_contains($text, 'SSD'));

    expect($hdd->fresh()->qty_on_hand)->toBe(1);
});

it('issues only pieces in stock, takes them back, and keeps the count equal to the pieces', function () {
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1', 'SN-2', 'SN-3']]);
    [$one, $two] = ($this->unitIds)(['SN-1', 'SN-2']);

    ($this->move)(['type' => 'issue', 'unit_ids' => [$one, $two], 'note' => 'ห้อง server'])->assertSessionHasNoErrors();
    expect($this->part->fresh()->qty_on_hand)->toBe(1)->and(($this->inStock)())->toBe(1)
        ->and(PartUnit::find($one)->status)->toBe('issued');

    // Already out: cannot go again, and nothing moves.
    ($this->move)(['type' => 'issue', 'unit_ids' => [$one]])->assertSessionHasErrors('unit_ids');
    ($this->move)(['type' => 'issue', 'quantity' => 1])->assertSessionHasErrors('unit_ids');

    ($this->move)(['type' => 'return', 'unit_ids' => [$two], 'note' => 'ไม่ได้ใช้'])->assertSessionHasNoErrors();
    expect($this->part->fresh()->qty_on_hand)->toBe(2)->and(($this->inStock)())->toBe(2)
        ->and(PartUnit::find($two)->status)->toBe('in_stock')
        ->and(StockMovement::orderByDesc('id')->first()->balance_after)->toBe(2);
});

it('takes broken pieces off with a reason, into the activity log', function () {
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1', 'SN-2']]);
    [$one] = ($this->unitIds)(['SN-1']);

    ($this->move)(['type' => 'adjust', 'unit_ids' => [$one]])->assertSessionHasErrors('note');
    ($this->move)(['type' => 'adjust', 'unit_ids' => [$one], 'note' => 'เปิดไม่ติด'])->assertSessionHasNoErrors();

    expect($this->part->fresh()->qty_on_hand)->toBe(1)
        ->and(PartUnit::find($one)->status)->toBe('removed')
        ->and(StockMovement::orderByDesc('id')->first()->only(['type', 'quantity']))->toBe(['type' => 'adjust', 'quantity' => -1])
        ->and(Activity::where('event', 'part_units_removed')->sole()->properties['reason'])->toBe('เปิดไม่ติด');
});

it('corrects a serial with a reason, and the history keeps the old one', function () {
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1', 'SN-2']]);
    [$one] = ($this->unitIds)(['SN-1']);

    $this->actingAs($this->admin)->put("/part-units/{$one}", ['serial_number' => 'SN-2', 'reason' => 'x'])->assertSessionHasErrors('serial_number');
    $this->actingAs($this->admin)->put("/part-units/{$one}", ['serial_number' => 'SN-1A'])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->put("/part-units/{$one}", ['serial_number' => 'SN-1A', 'reason' => 'พิมพ์ผิด'])->assertSessionHasNoErrors();

    expect(PartUnit::find($one)->serial_number)->toBe('SN-1A')
        ->and(PartUnitEvent::where('part_unit_id', $one)->orderBy('id')->pluck('serial_number')->all())->toBe(['SN-1', 'SN-1A'])
        ->and(Activity::where('event', 'part_unit_corrected')->count())->toBe(1);

    $this->actingAs($this->admin)->getJson("/part-units/{$one}/history")->assertOk()
        ->assertJsonPath('events.0.action', 'receive')->assertJsonPath('events.1.action', 'correct');
});

it('starts tracking only with a serial for every piece on hand, and stopping keeps the serials', function () {
    $ram = createPart(['code' => 'RAM', 'name' => 'RAM'], stock: 3);
    $start = fn (array $data) => $this->actingAs($this->admin)->post("/parts/{$ram->id}/serials/start", $data);

    $this->actingAs($this->admin)->get("/parts/{$ram->id}/serials/start")->assertInertia(fn (Assert $page) => $page
        ->component('Inventory/Parts/StartTracking')->where('part.qty_on_hand', 3));
    $start(['serials' => ['R-1', 'R-2']])->assertSessionHasErrors('serials');
    expect($ram->fresh()->track_serial)->toBeFalse();

    $start(['serials' => ['R-1', 'R-2', 'R-3']])->assertSessionHasNoErrors();
    expect($ram->fresh()->only(['track_serial', 'qty_on_hand']))->toBe(['track_serial' => true, 'qty_on_hand' => 3])
        ->and(PartUnit::where('part_id', $ram->id)->where('source', 'backfill')->count())->toBe(3);

    // Off: counted by quantity again, serials kept.
    $this->actingAs($this->admin)->post("/parts/{$ram->id}/serials/stop")->assertSessionHasNoErrors();
    ($this->move)(['type' => 'issue', 'quantity' => 1], part: $ram)->assertSessionHasNoErrors();
    expect($ram->fresh()->only(['track_serial', 'qty_on_hand']))->toBe(['track_serial' => false, 'qty_on_hand' => 2])
        ->and(PartUnit::where('part_id', $ram->id)->count())->toBe(3);

    // On again: the old pieces still here are kept, the missing one goes; the count matches the stock.
    [$r1, $r2] = PartUnit::whereIn('serial_number', ['R-1', 'R-2'])->orderBy('id')->pluck('id')->all();
    $start(['keep_ids' => [$r1, $r2], 'serials' => ['R-4']])->assertSessionHasErrors('serials');
    $start(['keep_ids' => [$r1], 'serials' => ['R-4']])->assertSessionHasNoErrors();
    expect($ram->fresh()->qty_on_hand)->toBe(2)
        ->and(PartUnit::where('part_id', $ram->id)->where('status', 'in_stock')->pluck('serial_number')->sort()->values()->all())->toBe(['R-1', 'R-4'])
        ->and(PartUnit::where('part_id', $ram->id)->whereIn('serial_number', ['R-2', 'R-3'])->pluck('status')->unique()->all())->toBe(['removed']);
});

it('leaves tracking settings to whoever holds parts.serials', function () {
    $ram = createPart(['code' => 'RAM'], stock: 1);
    $keeper = userWithRole('purchasing');

    $this->actingAs($keeper)->get("/parts/{$ram->id}/serials/start")->assertForbidden();
    $this->actingAs($keeper)->post("/parts/{$this->part->id}/serials/stop")->assertForbidden();
    $this->actingAs($keeper)->get('/part-categories')->assertForbidden();
    // The store still receives and takes pieces off.
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1']], $keeper)->assertSessionHasNoErrors();
});

it('gives new parts the setting of their category, which each part may change', function () {
    $this->actingAs($this->admin)->post('/part-categories', ['name' => 'HDD/SSD', 'track_serial' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/part-categories', ['name' => 'hdd/ssd'])->assertSessionHasErrors('name');
    $category = PartCategory::sole();

    $this->actingAs($this->admin)->post('/parts', ['code' => 'NEW1', 'name' => 'Disk', 'unit' => 'pcs', 'part_category_id' => $category->id]);
    $this->actingAs($this->admin)->post('/parts', ['code' => 'NEW2', 'name' => 'Disk', 'unit' => 'pcs', 'part_category_id' => $category->id, 'track_serial' => false]);
    $this->actingAs($this->admin)->post('/parts', ['code' => 'NEW3', 'name' => 'Cable', 'unit' => 'pcs']);

    expect(Part::where('code', 'NEW1')->value('track_serial'))->toBeTrue()
        ->and(Part::where('code', 'NEW2')->value('track_serial'))->toBeFalse()
        ->and(Part::where('code', 'NEW3')->value('track_serial'))->toBeFalse();

    // Editing a part does not switch it.
    $new1 = Part::where('code', 'NEW1')->first();
    $this->actingAs($this->admin)->put("/parts/{$new1->id}", ['code' => 'NEW1', 'name' => 'Disk', 'unit' => 'pcs', 'track_serial' => false]);
    expect($new1->fresh()->track_serial)->toBeTrue();
});

it('shows the pieces on the part page and offers only those in stock to pick', function () {
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1', 'SN-2']]);
    [$one] = ($this->unitIds)(['SN-1']);
    ($this->move)(['type' => 'issue', 'unit_ids' => [$one]]);

    $this->actingAs($this->admin)->get("/parts/{$this->part->id}")->assertInertia(fn (Assert $page) => $page
        ->where('part.track_serial', true)->where('part.qty_on_hand', 1)->where('units.total', 2));
    $this->actingAs($this->admin)->getJson("/parts/{$this->part->id}/units/options")->assertOk()
        ->assertJsonCount(1)->assertJsonPath('0.serial_number', 'SN-2');
    $this->actingAs($this->admin)->getJson("/parts/{$this->part->id}/units/options?status=issued&q=sn-")->assertJsonPath('0.serial_number', 'SN-1');
});

it('keeps the pieces of each company to itself', function () {
    ($this->move)(['type' => 'receive', 'serials' => ['SN-1']]);
    $other = createTenant('other');
    [$theirPart, $theirUnit] = asTenant($other, function () {
        $part = createTrackedPart(['code' => 'SSD'], ['THEIRS-1']);

        return [$part, PartUnit::where('part_id', $part->id)->value('id')];
    });

    $this->actingAs($this->admin)->getJson("/parts/{$theirPart->id}/units/options")->assertNotFound();
    $this->actingAs($this->admin)->getJson("/part-units/{$theirUnit}/history")->assertNotFound();
    // Their piece on our part: not found, nothing moves.
    ($this->move)(['type' => 'issue', 'unit_ids' => [$theirUnit]])->assertSessionHasErrors('unit_ids');
    expect(asTenant($other, fn () => PartUnit::find($theirUnit)->status))->toBe('in_stock');
});
