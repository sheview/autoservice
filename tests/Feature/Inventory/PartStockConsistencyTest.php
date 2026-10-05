<?php

use App\Modules\Inventory\Actions\IssuableParts;
use App\Modules\Inventory\Actions\IssuePartUnits;
use App\Modules\Inventory\Actions\PartsForCheckout;
use App\Modules\Inventory\Actions\RemovePartUnits;
use App\Modules\Inventory\Actions\ReturnPartUnits;
use App\Modules\Inventory\Models\PartUnit;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * One stock figure for a part followed by serial number: the pieces in stock. The part list, its
 * page, the receiving page, the issue/loan requests and the ticket page all read that same
 * number after every kind of change.
 */

it('shows the same stock everywhere after receiving, issuing, taking back and taking off', function () {
    $admin = userWithRole('admin_company');
    $ssd = createTrackedPart(['code' => 'SSD', 'name' => 'SSD 1TB', 'min_qty' => 1], ['S-1', 'S-2', 'S-3', 'S-4']);
    $id = fn (string $serial) => PartUnit::where('serial_number', $serial)->value('id');
    $ticket = openTicket($admin);

    $everywhere = function (int $expected) use ($admin, $ssd) {
        $pieces = PartUnit::where('part_id', $ssd->id)->where('status', 'in_stock')->count();
        expect($pieces)->toBe($expected)
            ->and($ssd->fresh()->qty_on_hand)->toBe($expected)
            ->and(app(PartsForCheckout::class)->handle([$ssd->id])[$ssd->id]['qty_on_hand'])->toBe($expected)
            ->and(collect(app(IssuableParts::class)->handle())->firstWhere('id', $ssd->id)['qty_on_hand'] ?? 0)->toBe($expected);

        $this->actingAs($admin)->get('/parts?search=SSD')->assertInertia(fn (Assert $page) => $page->where('parts.data.0.qty_on_hand', $expected));
        $this->actingAs($admin)->get("/parts/{$ssd->id}")->assertInertia(fn (Assert $page) => $page->where('part.qty_on_hand', $expected));
        $this->actingAs($admin)->getJson('/stock-receipts/parts?q=SSD')->assertJsonPath('0.qty_on_hand', $expected);
        $this->actingAs($admin)->getJson("/parts/{$ssd->id}/units/options")->assertJsonCount($expected);
    };

    $everywhere(4);

    $this->actingAs($admin)->post('/stock-receipts', ['part_id' => $ssd->id, 'quantity' => 1, 'serials' => ['S-5']])->assertSessionHasNoErrors();
    $everywhere(5);

    app(IssuePartUnits::class)->handle($ssd, [$id('S-1'), $id('S-2')], $admin, details: ['ticket_id' => $ticket->id]);
    $everywhere(3);

    app(ReturnPartUnits::class)->handle($ssd, [$id('S-2')], $admin, ['ticket_id' => $ticket->id, 'note' => 'spare']);
    $everywhere(4);

    app(RemovePartUnits::class)->handle($ssd, [$id('S-3')], $admin, 'dead on arrival');
    $everywhere(3);

    // The removed and the issued pieces are kept, never counted.
    expect(PartUnit::where('part_id', $ssd->id)->count())->toBe(5);
});
