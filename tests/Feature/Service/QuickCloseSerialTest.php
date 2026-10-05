<?php

use App\Modules\Inventory\Actions\IssuePartUnits;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Service\Actions\AssignTicket;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketRemovedPart;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Closing a job on a phone with parts followed by serial number: a piece chosen (or scanned)
 * for each one used, only pieces in stock, tied to the job and its device; pieces taken out of
 * the device are a note; the device's page tells the story.
 */

beforeEach(function () {
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->asset = createAsset(createAssetCategory(['name' => 'Server']), ['name' => 'DB server']);
    $this->ssd = createTrackedPart(['code' => 'SSD', 'name' => 'SSD 1TB'], ['S-1', 'S-2', 'S-3']);
    $this->fan = createPart(['code' => 'FAN', 'name' => 'Fan'], 5);

    $this->ticket = openTicket($this->helpdesk, ['asset_id' => $this->asset->id]);
    app(AssignTicket::class)->handle($this->ticket, $this->tech->id, $this->helpdesk);
    $this->unit = fn (string $serial) => PartUnit::where('serial_number', $serial)->value('id');
    $this->close = fn (array $data) => $this->actingAs($this->tech)
        ->post("/tickets/{$this->ticket->ulid}/close", $data + ['symptoms' => ['ดิสก์เสีย'], 'solutions' => ['เปลี่ยนอะไหล่'], 'approver_name' => 'Khun A']);
});

it('needs a piece chosen for each tracked part used, and takes only pieces in stock', function () {
    $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ulid}/close?part_search=SSD")->assertInertia(fn (Assert $page) => $page
        ->where('partOptions.0.track_serial', true)->where('dispositions', ['claim', 'return_customer', 'keep', 'discard']));

    ($this->close)(['parts' => [['part_id' => $this->ssd->id, 'qty' => 1]]])->assertSessionHasErrors('parts');
    // Out already: refused, and nothing at all is saved.
    app(IssuePartUnits::class)->handle($this->ssd, [($this->unit)('S-3')], $this->helpdesk);
    ($this->close)(['parts' => [['part_id' => $this->ssd->id, 'qty' => 1, 'unit_ids' => [($this->unit)('S-3')]]]])->assertSessionHasErrors();
    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_ASSIGNED)->and($this->ssd->fresh()->qty_on_hand)->toBe(2);

    ($this->close)([
        'parts' => [
            ['part_id' => $this->ssd->id, 'qty' => 1, 'unit_ids' => [($this->unit)('S-2')]],
            ['part_id' => $this->fan->id, 'qty' => 2],
        ],
        'removed' => [['item_name' => 'SSD 512GB ตัวเดิม', 'serial_number' => 'OLD-77', 'problem' => 'bad sectors', 'disposition' => 'claim']],
    ])->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(Ticket::STATUS_RESOLVED)
        ->and(PartUnit::find(($this->unit)('S-2'))->only(['status', 'ticket_id', 'asset_id']))
        ->toBe(['status' => 'issued', 'ticket_id' => $this->ticket->id, 'asset_id' => $this->asset->id])
        ->and($this->ssd->fresh()->qty_on_hand)->toBe(1)
        ->and($this->fan->fresh()->qty_on_hand)->toBe(3)
        // The piece taken out is a note, never stock.
        ->and(TicketRemovedPart::sole()->only(['item_name', 'serial_number', 'disposition', 'asset_id', 'user_name']))->toBe([
            'item_name' => 'SSD 512GB ตัวเดิม', 'serial_number' => 'OLD-77', 'disposition' => 'claim', 'asset_id' => $this->asset->id, 'user_name' => 'Tech One',
        ])
        ->and(PartUnit::where('serial_number', 'OLD-77')->exists())->toBeFalse();
});

it('refuses a removed piece without a known way to deal with it', function () {
    ($this->close)(['removed' => [['item_name' => 'PSU', 'disposition' => 'sell']]])->assertSessionHasErrors('removed.0.disposition');
    ($this->close)(['removed' => [['disposition' => 'keep']]])->assertSessionHasErrors('removed.0.item_name');
    expect(TicketRemovedPart::count())->toBe(0);
});

it('tells on the device page which part went in, with its serial and when, and what came out', function () {
    ($this->close)([
        'parts' => [['part_id' => $this->ssd->id, 'qty' => 1, 'unit_ids' => [($this->unit)('S-1')]]],
        'removed' => [['item_name' => 'SSD 512GB', 'serial_number' => 'OLD-1', 'disposition' => 'return_customer']],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->helpdesk)->get("/assets/{$this->asset->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('partHistory', 2)
        ->where('partHistory', fn ($rows) => collect($rows)->contains(fn ($row) => $row['kind'] === 'installed' && $row['serial'] === 'S-1'
                && $row['part'] === 'SSD 1TB' && $row['ticket']['ticket_no'] === $this->ticket->fresh()->ticket_no)
            && collect($rows)->contains(fn ($row) => $row['kind'] === 'removed' && $row['serial'] === 'OLD-1' && $row['disposition'] === 'return_customer')));

    $this->actingAs($this->helpdesk)->get("/tickets/{$this->ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('removedParts.0.serial_number', 'OLD-1'));
});
