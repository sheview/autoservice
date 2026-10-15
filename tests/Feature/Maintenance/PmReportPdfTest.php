<?php

use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\PmNumber;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The files sent to Gotenberg by the last request: [index.html, footer.html|null].
 *
 * @return array{0: string, 1: string|null}
 */
function pmPdfSent(): array
{
    $files = [];
    Http::assertSent(function (HttpRequest $request) use (&$files) {
        $files = [];
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === 'files') {
                $files[$part['filename']] = $part['contents'];
            }
        }

        return true;
    });

    return [$files['index.html'] ?? '', $files['footer.html'] ?? null];
}

beforeEach(function () {
    Storage::fake('local');
    config(['services.gotenberg.url' => 'http://gotenberg.test:3000']);
    Http::fake(['gotenberg.test:3000/*' => Http::response('%PDF-1.7 pm', 200, ['Content-Type' => 'application/pdf'])]);
    $this->travelTo('2026-02-10 10:00');

    $this->admin = userWithRole('admin_company', ['name' => 'Boss']);
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);
    $category = createAssetCategory(['name' => 'UPS']);
    $this->upsA = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'UPS Alpha', 'serial_number' => 'SN-ALPHA']);
    $this->upsB = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'UPS Bravo']);
    $contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->create(['asset_id' => $this->upsA->id]);
    $contract->contractAssets()->create(['asset_id' => $this->upsB->id]);
    createPmTemplate(['name' => 'UPS PM', 'asset_category_id' => $category->id, 'items' => [
        ['key' => 'battery', 'label' => 'ทดสอบแบตเตอรี่', 'type' => 'pass_fail', 'section' => 'แบตเตอรี่'],
        ['key' => 'load', 'label' => 'โหลดใช้งาน', 'type' => 'measure', 'unit' => '%', 'max' => 80],
    ]]);

    $plan = pmPlanWithAllRounds(['contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $this->tech->id]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->url = "/pm-visits/{$this->visit->ulid}";

    // The work done, a photo of a question, handed in with the technician's signature.
    $this->actingAs($this->tech)->post("{$this->url}/start");
    $this->itemA = $this->visit->items()->where('asset_id', $this->upsA->id)->first();
    $this->itemB = $this->visit->items()->where('asset_id', $this->upsB->id)->first();
    $this->actingAs($this->tech)->put("{$this->url}/items/{$this->itemA->id}", ['result' => 'failed', 'answers' => ['battery' => 'fail', 'load' => 92], 'remarks' => ['battery' => 'แบตเสื่อม'], 'note' => 'ต้องเปลี่ยนแบต']);
    $this->actingAs($this->tech)->put("{$this->url}/items/{$this->itemB->id}", ['result' => 'passed', 'answers' => ['battery' => 'pass', 'load' => 40]]);
    $this->actingAs($this->tech)->post("{$this->url}/items/{$this->itemA->id}/photos", ['photo' => UploadedFile::fake()->image('batt.jpg', 1600, 1200), 'question' => 'battery']);

    $this->approve = function (string $summary = 'สรุป') {
        $this->actingAs($this->tech)->post("{$this->url}/submit", ['summary' => $summary, 'technician_signature' => signaturePng()]);
        $this->actingAs($this->helpdesk)->post("{$this->url}/move", ['action' => 'review']);
        $this->actingAs($this->admin)->post("{$this->url}/move", ['action' => 'approve']);
    };
    $this->move = fn ($user, string $action, ?string $note = null) => $this->actingAs($user)->post("{$this->url}/move", ['action' => $action, 'note' => $note]);
});

it('prints the work order as a draft before approval, then the approved version with page numbers', function () {
    $this->actingAs($this->helpdesk)->get("{$this->url}/pdf")->assertOk();
    [$html, $footer] = pmPdfSent();
    expect($html)->toContain('ฉบับร่าง — ยังไม่อนุมัติ')
        ->and($footer)->toContain(PmNumber::shown($this->visit->visit_no, $this->visit->tenant_id))->toContain('DRAFT')->toContain('class="pageNumber"')->toContain('class="totalPages"');

    ($this->approve)('ทุกเครื่องตรวจแล้ว');

    $this->actingAs($this->helpdesk)->get("{$this->url}/pdf?download=1")->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$this->visit->visit_no.'_v1.pdf"');
    [$html, $footer] = pmPdfSent();
    expect($html)->toContain('ฉบับที่ 1')->not->toContain('ฉบับร่าง')
        ->toContain('UPS Alpha')->toContain('UPS Bravo')
        ->toContain('ทุกเครื่องตรวจแล้ว')
        // a question, its criterion and its outcome; the reading out of range is a fail
        ->toContain('แบตเตอรี่')->toContain('ทดสอบแบตเตอรี่')->toContain('≤ 80 %')->toContain('92 %')->toContain('แบตเสื่อม')
        // the photo, shrunk to JPEG, and the drawn signature
        ->toContain('data:image/jpeg;base64,')->toContain('data:image/png;base64,')
        ->toContain('ผู้อนุมัติ')->toContain('Boss')
        ->and($footer)->toContain('v1');
});

it('prints the report of one piece of equipment from the same data', function () {
    ($this->approve)();

    $this->actingAs($this->helpdesk)->get("{$this->url}/equipment/{$this->itemA->id}/pdf")->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="'.$this->visit->visit_no.'_'.$this->upsA->asset_code.'_v1.pdf"');
    [$html, $footer] = pmPdfSent();
    expect($html)->toContain('ใบตรวจเช็กบำรุงรักษาเชิงป้องกัน (PM)')->toContain('UPS Alpha')->toContain('SN-ALPHA')->not->toContain('UPS Bravo')
        ->and($footer)->toContain($this->upsA->asset_code);

    // An item of another work order is not in this report.
    $this->actingAs($this->helpdesk)->get("{$this->url}/equipment/999999/pdf")->assertNotFound();
});

it('keeps every approved version printable as it was', function () {
    ($this->approve)('ฉบับแรก');
    ($this->move)($this->admin, 'reopen', 'แก้สรุป');
    ($this->approve)('ฉบับแก้ไข');

    $this->actingAs($this->helpdesk)->get("{$this->url}/pdf?version=1")->assertOk();
    expect(pmPdfSent()[0])->toContain('ฉบับแรก')->toContain('ฉบับที่ 1');
    $this->actingAs($this->helpdesk)->get("{$this->url}/pdf")->assertOk();
    expect(pmPdfSent()[0])->toContain('ฉบับแก้ไข')->toContain('ฉบับที่ 2');
    $this->actingAs($this->helpdesk)->get("{$this->url}/pdf?version=9")->assertNotFound();
});

it('gives a customer account only the report given to the customer', function () {
    $client = userWithRole('customer_it', ['customer_id' => $this->customer->id]);

    // Not approved and published yet: nothing.
    $this->actingAs($client)->get("{$this->url}/pdf")->assertNotFound();
    ($this->approve)('ฉบับแรก');
    $this->actingAs($client)->get("{$this->url}/pdf")->assertNotFound();

    ($this->move)($this->admin, 'publish');
    $this->actingAs($client)->get("{$this->url}/pdf?version=1")->assertOk();
    expect(pmPdfSent()[0])->toContain('ฉบับแรก');

    // A correction approved later reaches the customer only when published.
    ($this->move)($this->admin, 'reopen', 'แก้');
    ($this->approve)('ฉบับแก้ไข');
    $this->actingAs($client)->get("{$this->url}/pdf?version=2")->assertOk();
    expect(pmPdfSent()[0])->toContain('ฉบับแรก')->not->toContain('ฉบับแก้ไข');
    ($this->move)($this->admin, 'publish');
    $this->actingAs($client)->get("{$this->url}/pdf")->assertOk();
    expect(pmPdfSent()[0])->toContain('ฉบับแก้ไข');

    // Another customer's account sees nothing of it.
    $other = userWithRole('customer_it', ['customer_id' => createCustomer()->id]);
    $this->actingAs($other)->get("{$this->url}/pdf")->assertForbidden();
});

it('keeps the reports to their own company', function () {
    $theirs = asTenant(createTenant('other'), function () {
        $customer = createCustomer();
        $contract = createContract($customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);

        return pmPlanWithAllRounds(['contract_id' => $contract->id, 'title' => 'Theirs', 'interval_months' => 6])->visits()->first();
    });

    $this->actingAs($this->admin)->get("/pm-visits/{$theirs->ulid}/pdf")->assertNotFound();
    expect(PmVisit::count())->toBe(4);
});

// --- round report ------------------------------------------------------------------

it('joins the approved work orders of a customer within a period into one PDF, after a cover', function () {
    $query = ['customer_id' => $this->customer->id, 'from' => '2026-01-01', 'to' => '2026-12-31'];

    // nothing approved yet: a message, no PDF
    $this->actingAs($this->helpdesk)->from('/pm-reports')->get(route('maintenance.reports.round', $query))
        ->assertRedirect('/pm-reports')->assertSessionHas('error', __('maintenance.round_report.empty'));
    Http::assertNothingSent();

    ($this->approve)();
    $response = $this->actingAs($this->helpdesk)->get(route('maintenance.reports.round', $query))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('PM-round-')->toContain('2026-01-01-2026-12-31.pdf');

    // the cover, the one approved sheet (round 2 is not approved), then one merge of both in order
    $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
    $converts = $sent->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/forms/chromium/convert/html'))->values();
    $merges = $sent->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/forms/pdfengines/merge'))->values();
    $html = fn (HttpRequest $r) => collect($r->data())->firstWhere('filename', 'index.html')['contents'];
    expect($converts)->toHaveCount(2)
        ->and($html($converts[0]))->toContain('รายงานสรุปการบำรุงรักษาเชิงป้องกัน')->toContain(PmNumber::shown($this->visit->visit_no, $this->visit->tenant_id))
        ->and($html($converts[1]))->toContain('UPS Alpha')
        ->and($merges)->toHaveCount(1)
        ->and(collect($merges[0]->data())->pluck('filename')->all())->toBe(['001.pdf', '002.pdf']);

    // PM report viewers only
    $this->actingAs($this->tech)->get(route('maintenance.reports.round', $query))->assertForbidden();
    // a customer or a plan is needed
    $this->actingAs($this->helpdesk)->get(route('maintenance.reports.round', ['from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertSessionHasErrors('customer_id');
});

it('prints the round report from the browser: the cover, then each approved sheet, without the PDF service', function () {
    $query = ['customer_id' => $this->customer->id, 'from' => '2026-01-01', 'to' => '2026-12-31', 'print' => 1];
    $this->actingAs($this->helpdesk)->from('/pm-reports')->get(route('maintenance.reports.round', $query))
        ->assertRedirect('/pm-reports')->assertSessionHas('error', __('maintenance.round_report.empty'));

    ($this->approve)();
    $page = $this->actingAs($this->helpdesk)->get(route('maintenance.reports.round', $query))->assertOk()->getContent();
    expect($page)->toContain('window.print()')->toContain('รายงานสรุปการบำรุงรักษาเชิงป้องกัน')
        ->and(substr_count($page, 'class="round-sheet"'))->toBe(1)
        ->and(strpos($page, 'รายงานสรุปการบำรุงรักษาเชิงป้องกัน'))->toBeLessThan(strpos($page, 'UPS Alpha'))
        // the customer's copy
        ->and($page)->not->toContain('Response Time (Office Use Only)');
    Http::assertNothingSent();
});

it('says so when the PDF service cannot join the round report', function () {
    ($this->approve)();
    // another host: the fakes of beforeEach answer every request to the first one
    config(['services.gotenberg.url' => 'http://pdf.test']);
    Http::fake(['pdf.test/forms/pdfengines/merge' => Http::response('down', 503), 'pdf.test/*' => Http::response('%PDF-1.7', 200)]);

    $this->actingAs($this->helpdesk)->from('/pm-reports')
        ->get(route('maintenance.reports.round', ['customer_id' => $this->customer->id, 'from' => '2026-01-01', 'to' => '2026-12-31']))
        ->assertRedirect('/pm-reports')->assertSessionHas('error', __('document.unavailable'));
});
