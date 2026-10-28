<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\PmNumber;
use Illuminate\Support\Facades\Http;

function sheetSent(): string
{
    $html = '';
    Http::assertSent(function ($request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === 'files' && ($part['filename'] ?? null) === 'index.html') {
                $html = (string) $part['contents'];
            }
        }

        return true;
    });

    return $html;
}

beforeEach(function () {
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 sheet', 200, ['Content-Type' => 'application/pdf'])]);
    $this->travelTo('2026-06-10 09:00');
    $this->admin = userWithRole('admin_company');
    $this->tech = userWithRole('technician');

    $ups = createAssetCategory(['name' => 'UPS', 'code_prefix' => 'UPS']);
    $pc = createAssetCategory(['name' => 'PC', 'code_prefix' => 'PC']);
    createPmTemplate(['code' => 'UPS', 'asset_category_id' => $ups->id, 'blocks' => ['acknowledgement' => true, 'response_time' => true],
        'items' => [['key' => 'volt', 'label' => 'แรงดันขาเข้า', 'type' => 'number', 'unit' => 'V', 'min' => 210, 'max' => 240]]]);
    createPmTemplate(['code' => 'PC-SET', 'asset_category_id' => $pc->id, 'layout' => PmChecklist::LAYOUT_GROUPED_TABLE,
        'items' => [['key' => 'cleaning', 'label' => 'ทำความสะอาด', 'type' => 'pass_fail']]]);
    $customer = createCustomer(['name' => 'ลูกค้า ก']);
    $contract = createContract($customer);
    foreach ([[$ups, 'UPS ชั้น 2'], [$ups, 'UPS ชั้น 3'], [$pc, 'PC บัญชี 1'], [$pc, 'PC บัญชี 2']] as [$category, $name]) {
        $contract->contractAssets()->create(['asset_id' => createAsset($category, ['customer_id' => $customer->id, 'name' => $name])->id]);
    }
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM รวม', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->url = "/pm-visits/{$this->visit->ulid}";
    $this->actingAs($this->tech)->post("/pm-work/{$this->visit->ulid}/arrive");
    foreach ($this->visit->items()->orderBy('id')->get() as $i => $item) {
        $answers = $i === 0 ? ['volt' => 250] : ($i === 1 ? ['volt' => 220] : ['cleaning' => 'pass']);
        $this->actingAs($this->tech)->put("{$this->url}/items/{$item->id}", ['result' => $i === 0 ? 'failed' : 'passed', 'note' => 'แรงดันสูง', 'answers' => $answers]);
    }
});

it('prints the sheet: header with the number and tracking QR, a summary page, a page per data center asset, a table for PCs', function () {
    $this->actingAs($this->admin)->get("{$this->url}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    $html = sheetSent();

    expect($html)
        // Thai font inside the file, no web font
        ->toContain("@font-face { font-family: 'Sarabun'")->toContain('data:font/ttf;base64,')->not->toContain('fonts.googleapis')
        ->toContain('ใบตรวจเช็กบำรุงรักษาเชิงป้องกัน (PM)')->toContain('ครั้งที่ 1')
        ->toContain(PmNumber::shown($this->visit->visit_no, $this->visit->tenant_id))
        ->toContain('<svg')
        // summary: counts and the equipment
        ->toContain('สรุปผลการตรวจ')->toContain('UPS ชั้น 2')->toContain('PC บัญชี 2')
        // the reading out of range in red, with its range
        ->toContain('210 – 240 V')->toContain('#b91c1c')
        // grouped PCs: one table with the field as a column
        ->toContain('class="grouped"')->toContain('ทำความสะอาด')
        // the signature block, fixed at the foot of every page
        ->toContain('class="sign-block"')->toContain('ผู้รับบริการ')
        // office use shown on the company's copy
        ->toContain('Response Time (Office Use Only)');

    // a page per data center asset (2) and one for the PC table: 3 page breaks after the summary
    expect(substr_count($html, 'class="page"'))->toBe(3)
        // the status badge is for the screen only
        ->and($html)->not->toContain('class="screen-only');
});

it('leaves office use out of the customer\'s copy, and shows the same HTML on screen with the status badge', function () {
    $this->actingAs($this->admin)->get("{$this->url}/pdf?copy=customer")->assertOk();
    expect(sheetSent())->not->toContain('Response Time (Office Use Only)')->toContain('สำเนาลูกค้า');

    $screen = $this->actingAs($this->admin)->get("{$this->url}/sheet")->assertOk()->getContent();
    expect($screen)->toContain('class="screen-only')->toContain('กำลังทำ')->toContain('class="sign-block"')
        ->toContain('@media print { .screen-only { display: none; } }')->not->toContain('window.print()');

    $this->actingAs(userWithRole('technician', tenant: createTenant('other')))->get("{$this->url}/sheet")->assertNotFound();
});

it('prints from the browser without the PDF service: the whole sheet, one piece of equipment, or the customer\'s copy', function () {
    $print = $this->actingAs($this->admin)->get("{$this->url}/sheet?print=1")->assertOk()->getContent();
    expect($print)->toContain('window.print()')->toContain('class="sign-block"')->toContain('Response Time (Office Use Only)')
        ->and(substr_count($print, 'class="page"'))->toBe(3);

    // one piece of equipment: its page only, no summary
    $ups = $this->visit->items()->orderBy('id')->first();
    $one = $this->actingAs($this->admin)->get("{$this->url}/sheet?print=1&item={$ups->id}")->assertOk()->getContent();
    expect($one)->toContain('window.print()')->toContain('UPS ชั้น 2')->not->toContain('UPS ชั้น 3')->not->toContain('PC บัญชี 1');

    // the customer's copy: no office use
    expect($this->actingAs($this->admin)->get("{$this->url}/sheet?print=1&copy=customer")->getContent())
        ->not->toContain('Response Time (Office Use Only)')->toContain('สำเนาลูกค้า');

    Http::assertNothingSent();
    $this->actingAs(userWithRole('technician'))->get("{$this->url}/sheet?print=1")->assertForbidden();
});

it('leaves the approver off the signature block until they sign, and lets them sign when approving', function () {
    $approverBox = '<div style="font-weight: 600">'.__('document.pm_sheet.approver').'</div>';
    $this->actingAs($this->tech)->post("{$this->url}/submit", ['technician_signature' => signaturePng()])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("{$this->url}/move", ['action' => 'review'])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get("{$this->url}/pdf")->assertOk();
    expect(sheetSent())->toContain('<div style="font-weight: 600">'.__('document.pm_sheet.engineer').'</div>')
        ->not->toContain($approverBox)
        ->toContain('grid-template-columns: repeat(2,');

    // the company does not ask for it, but the approver may sign
    $this->actingAs($this->admin)->post("{$this->url}/move", ['action' => 'approve', 'approver_signature' => signaturePng()])->assertSessionHasNoErrors();
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 sheet', 200, ['Content-Type' => 'application/pdf'])]);
    $this->actingAs($this->admin)->get("{$this->url}/pdf")->assertOk();
    expect(sheetSent())->toContain($approverBox)->toContain('grid-template-columns: repeat(3,');
});

it('turns the equipment table when it has many fields: a row per field, six pieces to a table', function () {
    $checks = fn (string $answer) => array_map(fn (int $n) => ['key' => "c{$n}", 'label' => "Check System Status {$n}", 'type' => 'pass_fail', 'answer' => $answer], range(1, 7));
    $items = array_map(fn (int $n) => [
        'asset' => ['asset_code' => sprintf('AP-%05d', $n), 'name' => "Access Point {$n}"],
        'checks' => $checks($n === 2 ? 'fail' : 'pass'),
        'result' => $n === 2 ? 'failed' : 'passed',
        'note' => null,
    ], range(1, 7));

    $html = view('documents.partials.pm-sheet-grouped', ['items' => $items])->render();

    // 7 pieces: two tables, each field a row (its label written once per table)
    expect(substr_count($html, 'class="grouped turned"'))->toBe(2)
        ->and(substr_count($html, 'Check System Status 1<'))->toBe(2)
        ->and($html)->toContain('AP-00007')->toContain('รายการตรวจ')->toContain('#b91c1c')
        // the second table keeps the width of six pieces
        ->and(substr_count($html, 'class="blank"'))->toBe(5 * (1 + 7 + 2));

    // one piece: one table as wide as it, no empty columns
    expect(view('documents.partials.pm-sheet-grouped', ['items' => [$items[0]]])->render())->not->toContain('class="blank"');

    // few fields: a column per field, as before
    $few = array_map(fn (array $item) => ['checks' => array_slice($item['checks'], 0, 3)] + $item, $items);
    expect(view('documents.partials.pm-sheet-grouped', ['items' => $few])->render())
        ->toContain('class="grouped"')->not->toContain('turned');
});
