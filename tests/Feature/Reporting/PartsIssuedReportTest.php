<?php

use App\Modules\Inventory\Actions\IssuePartUnits;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Actions\ReturnPartUnits;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\StockMovement;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Report "parts replaced / issued" with serial numbers, filtered by MA contract, customer, period
 * and a search, as a page, Excel and a printable page; and finding pieces by serial on the part
 * and request lists.
 */

beforeEach(function () {
    $this->travelTo('2026-10-15 10:00');
    $this->admin = userWithRole('admin_company');
    $this->acme = createCustomer(['name' => 'Acme']);
    $this->beta = createCustomer(['name' => 'Beta']);
    $this->contract = createContract($this->acme);
    $this->ssd = createTrackedPart(['code' => 'SSD', 'name' => 'SSD 1TB', 'brand' => 'Samsung'], ['S-1', 'S-2', 'S-3']);
    $this->fan = createPart(['code' => 'FAN', 'name' => 'Fan'], 10);
    $this->unit = fn (string $serial) => PartUnit::where('serial_number', $serial)->value('id');

    $this->acmeTicket = openTicket($this->admin, ['customer_id' => $this->acme->id, 'contract_id' => $this->contract->id]);
    $this->betaTicket = openTicket($this->admin, ['customer_id' => $this->beta->id]);
    app(IssuePartUnits::class)->handle($this->ssd, [($this->unit)('S-1'), ($this->unit)('S-2')], $this->admin, details: ['ticket_id' => $this->acmeTicket->id]);
    app(RecordStockMovement::class)->handle($this->fan, StockMovement::TYPE_ISSUE, 2, $this->admin, ['ticket_id' => $this->betaTicket->id]);
    // S-2 came back afterwards.
    app(ReturnPartUnits::class)->handle($this->ssd, [($this->unit)('S-2')], $this->admin, ['note' => 'not needed']);
    $this->url = '/reports/parts-issued?from=2026-10-01&to=2026-10-31';
});

it('lists what left stock with the serials of the pieces, filtered by contract, customer and search', function () {
    $this->actingAs($this->admin)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->component('Reporting/PartsIssued')
        ->where('rows.total', 2));

    $this->actingAs($this->admin)->get("{$this->url}&contract_id={$this->contract->id}")->assertInertia(fn (Assert $page) => $page
        ->where('rows.total', 1)
        ->where('rows.data.0.part.code', 'SSD')
        ->where('rows.data.0.quantity', 2)
        ->where('rows.data.0.serials', ['S-1', 'S-2'])
        ->where('rows.data.0.returned', ['S-2'])
        ->where('rows.data.0.customer', 'Acme')
        ->where('rows.data.0.contract', $this->contract->contract_no)
        ->where('rows.data.0.ticket.ticket_no', $this->acmeTicket->fresh()->ticket_no));

    $this->actingAs($this->admin)->get("{$this->url}&customer_id={$this->beta->id}")->assertInertia(fn (Assert $page) => $page
        ->where('rows.total', 1)->where('rows.data.0.part.code', 'FAN')->where('rows.data.0.serials', []));

    $this->actingAs($this->admin)->get("{$this->url}&search=s-1")->assertInertia(fn (Assert $page) => $page
        ->where('rows.total', 1)->where('rows.data.0.part.code', 'SSD'));

    // Another period: nothing.
    $this->actingAs($this->admin)->get('/reports/parts-issued?from=2026-09-01&to=2026-09-30')->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
});

it('exports the same rows to Excel and a printable page', function () {
    Excel::fake();
    $this->actingAs($this->admin)->get("/reports/parts-issued/export?from=2026-10-01&to=2026-10-31&contract_id={$this->contract->id}")->assertOk();
    Excel::assertDownloaded('parts-issued-20261001-20261031.xlsx', fn ($export) => count($export->array()) === 1
        && $export->array()[0][5] === "S-1\nS-2" && $export->array()[0][6] === 'S-2');

    $html = $this->actingAs($this->admin)->get('/reports/parts-issued/print?from=2026-10-01&to=2026-10-31')->assertOk()->getContent();
    expect($html)->toContain('<span class="sn">S-1</span>')->toContain('<span class="sn sn-back">S-2</span>')->toContain('Acme');
});

it('is for whoever may see reports, and shows only the own company', function () {
    $this->actingAs(userWithRole('technician'))->get($this->url)->assertForbidden();

    $other = createTenant('other');
    asTenant($other, function () {
        $part = createTrackedPart(['code' => 'SSD'], ['OTHER-1']);
        app(IssuePartUnits::class)->handle($part, [PartUnit::where('part_id', $part->id)->value('id')], userWithRole('admin_company'));
    });
    $this->actingAs($this->admin)->get("{$this->url}&search=OTHER")->assertInertia(fn (Assert $page) => $page->where('rows.total', 0));
});

it('finds pieces by serial on the part list and the request list, with their history', function () {
    $this->actingAs($this->admin)->get('/parts?search=s-3')->assertInertia(fn (Assert $page) => $page
        ->where('parts.total', 1)->where('parts.data.0.code', 'SSD')
        ->where('serialHits.0.serial_number', 'S-3')->where('serialHits.0.status', 'in_stock'));

    $this->actingAs($this->admin)->getJson('/part-units/'.($this->unit)('S-2').'/history')
        ->assertJsonPath('events.0.action', 'receive')->assertJsonPath('events.1.action', 'issue')
        ->assertJsonPath('events.1.ticket.ticket_no', $this->acmeTicket->fresh()->ticket_no)
        ->assertJsonPath('events.2.action', 'return')->assertJsonPath('events.2.reason', 'not needed');

    $this->actingAs($this->admin)->get('/checkout-requests?search=S-1&status=all')->assertInertia(fn (Assert $page) => $page
        ->where('serialHits.0.serial_number', 'S-1'));
});
