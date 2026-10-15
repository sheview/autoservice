<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmPlanAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function pmEquipmentFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'pm-equipment').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'pm-equipment.xlsx', null, null, true);
}

const PM_HEADINGS = ['ชื่อ', 'หมวด', 'Serial Number', 'ชั้น', 'ห้อง', 'รหัสเครื่องแม่ (ชุด)', 'แบบฟอร์ม PM (รหัส)'];

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-02-10 09:00');
    $this->admin = userWithRole('admin_company');
    $this->tech = userWithRole('technician');

    $this->pcs = createAssetCategory(['name' => 'คอมพิวเตอร์', 'code_prefix' => 'PC']);
    $this->monitors = createAssetCategory(['name' => 'จอภาพ', 'code_prefix' => 'MON']);
    $this->ups = createAssetCategory(['name' => 'UPS', 'code_prefix' => 'UPS']);
    createPmTemplate(['code' => 'GENERAL', 'items' => [['key' => 'look', 'label' => 'ตรวจสภาพ', 'type' => 'pass_fail']]]);
    createPmTemplate(['code' => 'PC-SET', 'asset_category_id' => $this->pcs->id, 'layout' => PmChecklist::LAYOUT_GROUPED_TABLE,
        'items' => [['key' => 'cleaning', 'label' => 'ทำความสะอาด', 'type' => 'pass_fail']]]);
    // A form tied to no category of the file: only the file's column gives it.
    createPmTemplate(['code' => 'DC-UPS', 'asset_category_id' => createAssetCategory(['name' => 'อื่น', 'code_prefix' => 'OT'])->id,
        'items' => [['key' => 'load', 'label' => 'โหลด', 'type' => 'number', 'unit' => '%', 'max' => 80]]]);

    $this->customer = createCustomer(['name' => 'Acme']);
    $this->existing = createAsset($this->pcs, ['customer_id' => $this->customer->id, 'name' => 'PC เดิม']);
    $this->contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $this->contract->contractAssets()->create(['asset_id' => $this->existing->id]);
    // Every asset of the contract (no list).
    $this->plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $this->contract->id, 'title' => 'PM Acme', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $this->url = "/pm-plans/{$this->plan->id}/import";
});

it('adds the equipment of a file to the customer, the contract and the plan, with its form and its set', function () {
    $this->actingAs($this->admin)->post($this->url, ['file' => pmEquipmentFile([
        PM_HEADINGS,
        ['PC บัญชี 1', 'คอมพิวเตอร์', 'SN-PC-1', '2', 'บัญชี', null, null],
        ['จอ บัญชี 1', 'จอภาพ', 'SN-MON-1', '2', 'บัญชี', 'SN-PC-1', 'PC-SET'],
        ['UPS-01', 'UPS', 'SN-UPS-1', '1', 'ห้อง UPS', null, 'DC-UPS'],
        ['UPS ผิดแบบฟอร์ม', 'UPS', 'SN-UPS-2', '1', 'ห้อง UPS', null, 'NOPE'],
        ['จอ ไม่มีแม่', 'จอภาพ', 'SN-MON-2', '2', 'บัญชี', 'SN-NONE', null],
    ])])->assertRedirect($this->url);

    $import = AssetImport::sole();
    expect($import->only(['status', 'created_rows', 'failed_rows']))->toBe(['status' => 'done', 'created_rows' => 3, 'failed_rows' => 2])
        ->and(collect($import->errors)->pluck('row')->all())->toBe([5, 6])
        ->and($import->errors[0]['messages'][0])->toContain('NOPE')
        // a refused row keeps nothing
        ->and(Asset::where('name', 'UPS ผิดแบบฟอร์ม')->exists())->toBeFalse();

    $pc = Asset::where('name', 'PC บัญชี 1')->first();
    $monitor = Asset::where('name', 'จอ บัญชี 1')->first();
    $ups = Asset::where('name', 'UPS-01')->first();
    expect($pc->only(['customer_id', 'floor', 'room']))->toBe(['customer_id' => $this->customer->id, 'floor' => '2', 'room' => 'บัญชี'])
        ->and($monitor->parent_asset_id)->toBe($pc->id)
        ->and($this->contract->contractAssets()->pluck('asset_id')->sort()->values()->all())->toBe([$this->existing->id, $pc->id, $monitor->id, $ups->id])
        // the asset already in the contract stays in the plan
        ->and(PmPlanAsset::where('pm_plan_id', $this->plan->id)->pluck('template_code', 'asset_id')->all())->toEqual([
            $this->existing->id => null, $pc->id => null, $monitor->id => 'PC-SET', $ups->id => 'DC-UPS',
        ]);

    // the work order: each piece with its form, the monitor under its PC
    $visit = app(StartPmVisit::class)->handle($this->plan->visits()->first(), $this->tech);
    expect($visit->items()->pluck('template_code', 'asset_id')->all())->toEqual([
        $this->existing->id => 'PC-SET', $pc->id => 'PC-SET', $monitor->id => 'PC-SET', $ups->id => 'DC-UPS',
    ]);

    $this->actingAs($this->admin)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Plans/Import')
        ->where('plan.assets_count', 4)
        ->where('imports.0.failed_rows', 2)
        ->where('forms', fn ($forms) => collect($forms)->pluck('code')->all() === ['DC-UPS', 'GENERAL', 'PC-SET']));
});

it('updates equipment already registered, and keeps rows to the plan\'s customer', function () {
    $other = createCustomer(['code' => 'OTHER', 'name' => 'Other']);
    $this->actingAs($this->admin)->post($this->url, ['file' => pmEquipmentFile([
        ['รหัสทรัพย์สิน', ...PM_HEADINGS, 'ลูกค้า (รหัส)'],
        [$this->existing->asset_code, 'PC เดิม (ย้ายห้อง)', 'คอมพิวเตอร์', null, '3', 'ประชุม', null, null, null],
        [null, 'PC ลูกค้าอื่น', 'คอมพิวเตอร์', 'SN-X', '1', null, null, null, 'OTHER'],
    ])]);

    expect(AssetImport::sole()->only(['updated_rows', 'failed_rows']))->toBe(['updated_rows' => 1, 'failed_rows' => 1])
        ->and($this->existing->fresh()->only(['name', 'floor', 'room']))->toBe(['name' => 'PC เดิม (ย้ายห้อง)', 'floor' => '3', 'room' => 'ประชุม'])
        ->and(Asset::where('customer_id', $other->id)->exists())->toBeFalse();
});

it('gives a template with the forms of the company, to who may change the plan and import assets', function () {
    $this->actingAs($this->admin)->get("{$this->url}/template")->assertOk()->assertDownload('pm-equipment-template.xlsx');

    Excel::fake();
    $this->actingAs($this->admin)->get("{$this->url}/template");
    Excel::assertDownloaded('pm-equipment-template.xlsx', function ($export) {
        $sheets = $export->sheets();

        return in_array('แบบฟอร์ม PM (รหัส)', $sheets[0]->headings(), true)
            && in_array('รหัสเครื่องแม่ (ชุด)', $sheets[0]->headings(), true)
            && collect($sheets[0]->array())->pluck(count($sheets[0]->headings()) - 1)->all() === ['PC-SET', 'PC-SET', 'DC-UPS']
            && collect($sheets[1]->array())->pluck(0)->all() === ['DC-UPS', 'GENERAL', 'PC-SET'];
    });

    // helpdesk changes plans but does not import assets; a technician neither
    $this->actingAs(userWithRole('helpdesk'))->get($this->url)->assertForbidden();
    $this->actingAs($this->tech)->post($this->url, ['file' => pmEquipmentFile([PM_HEADINGS])])->assertForbidden();
    $this->actingAs(userWithRole('admin_company', tenant: createTenant('other')))->get($this->url)->assertNotFound();
});

it('imports a thousand pieces of equipment in one file', function () {
    $rows = [PM_HEADINGS];
    for ($i = 1; $i <= 500; $i++) {
        $rows[] = ["PC {$i}", 'คอมพิวเตอร์', "SN-PC-{$i}", (string) intdiv($i, 100), 'ห้อง '.intdiv($i, 10), null, 'PC-SET'];
        $rows[] = ["จอ {$i}", 'จอภาพ', "SN-MON-{$i}", (string) intdiv($i, 100), 'ห้อง '.intdiv($i, 10), "SN-PC-{$i}", 'PC-SET'];
    }

    $started = microtime(true);
    $this->actingAs($this->admin)->post($this->url, ['file' => pmEquipmentFile($rows)]);
    $seconds = microtime(true) - $started;

    expect(AssetImport::sole()->only(['status', 'created_rows', 'failed_rows']))->toBe(['status' => 'done', 'created_rows' => 1000, 'failed_rows' => 0])
        ->and(PmPlanAsset::where('pm_plan_id', $this->plan->id)->count())->toBe(1001)
        ->and(Asset::whereNotNull('parent_asset_id')->count())->toBe(500)
        ->and($seconds)->toBeLessThan(180);
});
