<?php

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Inventory\Actions\CorrectPartUnit;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Platform\Models\Activity;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Issue/loan requests for parts followed by serial number: the pieces are chosen when handed out
 * (only pieces in stock), the papers print the serials of those very pieces as written then, and
 * a take-back after the hand-over puts them back with the reason and a corrected issue.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Boss']);
    $this->desk = userWithRole('helpdesk', ['name' => 'Desk One']);
    $this->staff = userWithRole('user', ['name' => 'Somchai Office']);
    $this->ssd = createTrackedPart(['code' => 'SSD', 'name' => 'SSD 1TB', 'brand' => 'Samsung', 'part_number' => '990PRO'], ['S-1', 'S-2', 'S-3', 'S-4']);
    $this->ticket = openTicket($this->desk, ['title' => 'Server disk']);
    $this->unit = fn (string $serial) => PartUnit::where('serial_number', $serial)->value('id');

    $this->approved = function (int $qty) {
        $this->actingAs($this->desk)->post('/checkout-requests', [
            'borrower_user_id' => $this->staff->id, 'purpose' => 'Replace disks', 'needed_by' => '2026-10-10', 'submit' => true,
            'ticket_id' => $this->ticket->id, 'items' => [['item_type' => 'part', 'part_id' => $this->ssd->id, 'qty' => $qty]],
        ])->assertSessionHasNoErrors();
        $request = CheckoutRequest::latest('id')->first();
        $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve")->assertSessionHasNoErrors();

        return $request->fresh();
    };
    $this->fulfill = fn ($line, array $data) => $this->actingAs($this->desk)->post("/checkout-items/{$line->id}/fulfill", $data);
});

it('hands out only the pieces chosen, in stock, as many as handed out', function () {
    $request = ($this->approved)(2);
    $line = $request->items->first();

    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('request.items.0.track_serial', true)->where('request.items.0.serials', null));

    ($this->fulfill)($line, ['qty' => 2])->assertSessionHasErrors('unit_ids');
    ($this->fulfill)($line, ['qty' => 2, 'unit_ids' => [($this->unit)('S-1')]])->assertSessionHasErrors('unit_ids');
    expect($this->ssd->fresh()->qty_on_hand)->toBe(4)->and($line->fresh()->qty_fulfilled)->toBe(0);

    ($this->fulfill)($line, ['qty' => 2, 'unit_ids' => [($this->unit)('S-1'), ($this->unit)('S-3')]])->assertSessionHasNoErrors();

    expect($this->ssd->fresh()->qty_on_hand)->toBe(2)
        ->and(PartUnit::where('checkout_item_id', $line->id)->pluck('serial_number')->sort()->values()->all())->toBe(['S-1', 'S-3'])
        ->and(PartUnit::where('serial_number', 'S-1')->first()->only(['status', 'ticket_id', 'asset_id']))
        ->toBe(['status' => 'issued', 'ticket_id' => $this->ticket->id, 'asset_id' => $this->ticket->asset_id]);

    // A piece already out cannot go again on another request.
    $second = ($this->approved)(1)->items->first();
    ($this->fulfill)($second, ['qty' => 1, 'unit_ids' => [($this->unit)('S-1')]])->assertSessionHasErrors('unit_ids');
});

it('prints the serials of the pieces handed out, as written then, many to a line without breaking', function () {
    $serials = array_map(fn ($n) => "BULK-{$n}", range(1, 30));
    $bulk = createTrackedPart(['code' => 'NIC', 'name' => 'Network card'], $serials);
    $this->actingAs($this->desk)->post('/checkout-requests', [
        'borrower_user_id' => $this->staff->id, 'purpose' => 'Rollout', 'needed_by' => '2026-10-10', 'submit' => true, 'ticket_id' => $this->ticket->id,
        'items' => [['item_type' => 'part', 'part_id' => $this->ssd->id, 'qty' => 2], ['item_type' => 'part', 'part_id' => $bulk->id, 'qty' => 30]],
    ])->assertSessionHasNoErrors();
    $request = CheckoutRequest::latest('id')->first();
    $this->actingAs($this->admin)->post("/checkout-requests/{$request->ulid}/approve")->assertSessionHasNoErrors();
    [$ssdLine, $bulkLine] = $request->fresh()->items->all();

    ($this->fulfill)($ssdLine, ['qty' => 2, 'unit_ids' => [($this->unit)('S-2'), ($this->unit)('S-4')]])->assertSessionHasNoErrors();
    ($this->fulfill)($bulkLine, ['qty' => 30, 'unit_ids' => PartUnit::where('part_id', $bulk->id)->pluck('id')->all()])->assertSessionHasNoErrors();

    // Corrected afterwards: the papers keep what was written when it went out.
    app(CorrectPartUnit::class)->handle(PartUnit::where('serial_number', 'S-2')->first(), ['serial_number' => 'S-2-FIXED'], 'typo', $this->admin);

    foreach (["/checkout-requests/{$request->ulid}/delivery-note/print", "/checkout-requests/{$request->ulid}/print"] as $url) {
        $html = $this->actingAs($this->desk)->get($url)->assertOk()->getContent();
        expect($html)->toContain('<span class="sn">S-2</span>')->toContain('<span class="sn">S-4</span>')
            ->not->toContain('S-2-FIXED')->not->toContain('S-1<')
            ->toContain('Samsung 990PRO')
            ->toContain('<span class="sn">BULK-30</span>')
            // A long list may run onto the next page instead of being cut.
            ->toContain('class="many-serials"')
            ->not->toContain('ฉบับแก้ไข');
    }
});

it('takes pieces back with a reason, into stock, and the papers become a corrected issue', function () {
    $request = ($this->approved)(2);
    $line = $request->items->first();
    ($this->fulfill)($line, ['qty' => 2, 'unit_ids' => [($this->unit)('S-1'), ($this->unit)('S-2')]])->assertSessionHasNoErrors();
    $back = fn (array $data, $user = null) => $this->actingAs($user ?? $this->desk)->post("/checkout-items/{$line->id}/return-parts", $data);

    $back(['qty' => 1, 'unit_ids' => [($this->unit)('S-1')]])->assertSessionHasErrors('reason');
    // Only pieces that went out on this line.
    $back(['qty' => 1, 'unit_ids' => [($this->unit)('S-3')], 'reason' => 'x'])->assertSessionHasErrors('unit_ids');
    $back(['qty' => 1, 'unit_ids' => [($this->unit)('S-1')], 'reason' => 'ใส่ไม่ได้'])->assertSessionHasNoErrors();

    expect(PartUnit::find(($this->unit)('S-1'))->only(['status', 'checkout_item_id', 'ticket_id']))
        ->toBe(['status' => 'in_stock', 'checkout_item_id' => null, 'ticket_id' => null])
        ->and($this->ssd->fresh()->qty_on_hand)->toBe(3)
        ->and($line->fresh()->qty_returned)->toBe(1)
        ->and(Activity::where('event', 'checkout_part_returned')->sole()->causer_id)->toBe($this->desk->id);

    $html = $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}/delivery-note/print")->getContent();
    expect($html)->toContain('ฉบับแก้ไขครั้งที่ 1')->toContain('<span class="sn sn-back">S-1</span>')->toContain('ใส่ไม่ได้');

    $this->actingAs($this->desk)->get("/checkout-requests/{$request->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('request.items.0.serials.returned.0.serial', 'S-1')->where('request.items.0.outstanding', 1));
});

it('holds no piece for a request cancelled before anything is handed out', function () {
    $this->actingAs($this->desk)->post('/checkout-requests', [
        'borrower_user_id' => $this->staff->id, 'purpose' => 'x', 'needed_by' => '2026-10-10', 'submit' => true, 'ticket_id' => $this->ticket->id,
        'items' => [['item_type' => 'part', 'part_id' => $this->ssd->id, 'qty' => 2]],
    ]);
    $request = CheckoutRequest::latest('id')->first();
    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/cancel")->assertSessionHasNoErrors();

    expect(PartUnit::where('status', 'in_stock')->count())->toBe(4)->and($this->ssd->fresh()->qty_on_hand)->toBe(4);
});

it('issues and takes back pieces on the ticket page, tied to the job and its device', function () {
    $post = fn (string $path, array $data) => $this->actingAs($this->desk)->post("/tickets/{$this->ticket->ulid}/parts{$path}", $data);

    $post('', ['part_id' => $this->ssd->id, 'quantity' => 1])->assertSessionHasErrors('unit_ids');
    $post('', ['part_id' => $this->ssd->id, 'quantity' => 1, 'unit_ids' => [($this->unit)('S-2')]])->assertSessionHasNoErrors();

    expect(PartUnit::find(($this->unit)('S-2'))->only(['status', 'ticket_id', 'asset_id']))
        ->toBe(['status' => 'issued', 'ticket_id' => $this->ticket->id, 'asset_id' => $this->ticket->asset_id]);
    $this->actingAs($this->desk)->get("/tickets/{$this->ticket->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('parts.items.0.track_serial', true)->where('parts.items.0.units.0.serial_number', 'S-2'));

    $post('/return', ['part_id' => $this->ssd->id, 'quantity' => 1, 'unit_ids' => [($this->unit)('S-3')]])->assertSessionHasErrors();
    $post('/return', ['part_id' => $this->ssd->id, 'quantity' => 1, 'unit_ids' => [($this->unit)('S-2')]])->assertSessionHasNoErrors();
    expect(PartUnit::find(($this->unit)('S-2'))->status)->toBe('in_stock')->and($this->ssd->fresh()->qty_on_hand)->toBe(4);
});

it('never hands out a piece of another company', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, function () {
        $part = createTrackedPart(['code' => 'SSD'], ['T-1']);

        return PartUnit::where('part_id', $part->id)->value('id');
    });
    $line = ($this->approved)(1)->items->first();

    ($this->fulfill)($line, ['qty' => 1, 'unit_ids' => [$theirs]])->assertSessionHasErrors('unit_ids');
    expect(asTenant($other, fn () => PartUnit::find($theirs)->status))->toBe('in_stock');
});

it('leaves a part followed by serial number to its own form on "hand out all"', function () {
    $request = ($this->approved)(2);

    $this->actingAs($this->desk)->post("/checkout-requests/{$request->ulid}/fulfill-all")->assertSessionHasErrors('request');
    expect($request->items->first()->fresh()->qty_fulfilled)->toBe(0)
        ->and($request->fresh()->status)->toBe('approved');
});
