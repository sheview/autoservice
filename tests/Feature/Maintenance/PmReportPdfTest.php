<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmVisit;
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

    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $this->tech->id]);
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
        ->and($footer)->toContain($this->visit->visit_no)->toContain('DRAFT')->toContain('class="pageNumber"')->toContain('class="totalPages"');

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
        ->toContain('อนุมัติโดย')->toContain('Boss')
        ->and($footer)->toContain('v1');
});

it('prints the report of one piece of equipment from the same data', function () {
    ($this->approve)();

    $this->actingAs($this->helpdesk)->get("{$this->url}/equipment/{$this->itemA->id}/pdf")->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="'.$this->visit->visit_no.'_'.$this->upsA->asset_code.'_v1.pdf"');
    [$html, $footer] = pmPdfSent();
    expect($html)->toContain('รายงาน PM รายอุปกรณ์')->toContain('UPS Alpha')->toContain('SN-ALPHA')->not->toContain('UPS Bravo')
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

        return app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'Theirs', 'interval_months' => 6])->visits()->first();
    });

    $this->actingAs($this->admin)->get("/pm-visits/{$theirs->ulid}/pdf")->assertNotFound();
    expect(PmVisit::count())->toBe(4);
});
