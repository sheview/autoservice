<?php

use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Asset\Models\StorageLocation;
use App\Modules\Asset\Support\AssetSheet;
use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = userWithRole('admin_company');
    $this->bkk = Branch::create(['code' => 'BKK', 'name' => 'กรุงเทพ']);
    $this->pc = createAssetCategory([
        'name' => 'คอมพิวเตอร์',
        'code_prefix' => 'PC',
        'spec_fields' => [
            ['key' => 'cpu', 'label' => 'CPU', 'type' => 'text', 'options' => [], 'required' => true],
            ['key' => 'ram_gb', 'label' => 'RAM', 'type' => 'number', 'options' => [], 'required' => false],
        ],
    ]);
});

/**
 * An uploaded .xlsx file with these rows (first row = headings).
 */
function xlsxUpload(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'assets').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'assets.xlsx', null, null, true);
}

function sheetRows(string $path): array
{
    return Excel::toCollection(null, $path)->first()->toArray();
}

const HEADINGS = ['รหัสทรัพย์สิน', 'ชื่อ', 'หมวด', 'สาขา', 'สถานะ', 'วันที่ซื้อ', 'ราคาซื้อ (บาท)', 'CPU [spec.cpu]', 'RAM [spec.ram_gb]'];

it('finds a category by its code or the short name before or inside its brackets', function () {
    $ups = createAssetCategory(['name' => 'เครื่องสำรองไฟ (UPS)', 'code_prefix' => 'UPS']);
    $acs = createAssetCategory(['name' => 'ระบบควบคุมการเข้าออก (Access Control)', 'code_prefix' => 'ACS']);
    // "Monitoring" fits two categories: it is not guessed
    createAssetCategory(['name' => 'ระบบตรวจวัดสภาพแวดล้อม (Monitoring)', 'code_prefix' => 'EMS']);
    createAssetCategory(['name' => 'ระบบตรวจวัดไฟฟ้า (Monitoring)', 'code_prefix' => 'PWR']);
    $row = fn (string $category) => [null, "เครื่อง {$category}", $category, null, null, null, null, null, null];

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => xlsxUpload([
        HEADINGS, $row('ups'), $row('เครื่องสำรองไฟ'), $row('Access Control'), $row('PC'), $row('Monitoring'), $row('ระบบตรวจวัดสภาพแวดล้อม'),
    ])])->assertSessionHasNoErrors();

    $import = AssetImport::first();
    expect(Asset::where('name', 'เครื่อง ups')->value('category_id'))->toBe($ups->id)
        ->and(Asset::where('name', 'เครื่อง เครื่องสำรองไฟ')->value('category_id'))->toBe($ups->id)
        ->and(Asset::where('name', 'เครื่อง Access Control')->value('category_id'))->toBe($acs->id)
        // the PC row misses its required CPU, and the Monitoring row is not guessed
        ->and(collect($import->errors)->pluck('row')->all())->toBe([5, 6])
        ->and($import->errors[1]['messages'][0])->toStartWith('ไม่พบหมวด "Monitoring"')
        ->and($import->created_rows)->toBe(4);
});

it('imports new rows, updates existing codes and reports bad rows by row number', function () {
    createAsset($this->pc, ['name' => 'Old name', 'specs' => ['cpu' => 'old']]); // PC-00001

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => xlsxUpload([
        HEADINGS,
        ['PC-00001', 'Updated name', 'คอมพิวเตอร์', 'BKK', 'ส่งซ่อม', '15/01/2569', '12,500.50', 'Core i5', 8],
        [null, 'New PC', 'คอมพิวเตอร์', null, null, null, null, 'Core i7', null],
        [null, 'Bad category', 'ไม่มีหมวดนี้', null, null, null, null, 'x', null],
        [null, 'No CPU', 'คอมพิวเตอร์', 'XYZ', null, null, null, null, null],
        [null, null, null, null, null, null, null, null, null],
    ])])->assertRedirect(route('asset.imports.index'))->assertSessionHasNoErrors();

    $import = AssetImport::first();
    expect($import->status)->toBe(AssetImport::STATUS_DONE)
        ->and($import->only(['total_rows', 'created_rows', 'updated_rows', 'failed_rows']))
        ->toBe(['total_rows' => 4, 'created_rows' => 1, 'updated_rows' => 1, 'failed_rows' => 2])
        ->and(collect($import->errors)->pluck('row')->all())->toBe([4, 5])
        ->and($import->errors[0]['messages'][0])->toStartWith('ไม่พบหมวด "ไม่มีหมวดนี้"')
        ->and($import->errors[1]['messages'])->toContain('ไม่พบสาขา "XYZ"');

    $updated = Asset::where('asset_code', 'PC-00001')->first();
    expect($updated->name)->toBe('Updated name')
        ->and($updated->status)->toBe(Asset::STATUS_IN_REPAIR)
        ->and($updated->branch_id)->toBe($this->bkk->id)
        ->and($updated->purchased_at->toDateString())->toBe('2026-01-15')
        ->and($updated->purchase_price)->toBe(1250050)
        ->and($updated->specs)->toBe(['cpu' => 'Core i5', 'ram_gb' => 8]);

    $created = Asset::where('name', 'New PC')->first();
    expect($created->asset_code)->toBe('PC-00002')
        ->and($created->status)->toBe(Asset::STATUS_IN_USE);
});

it('shows the import history', function () {
    $this->actingAs($this->admin)->post('/assets/imports', ['file' => xlsxUpload([HEADINGS, [null, 'A', 'คอมพิวเตอร์', null, null, null, null, 'x', null]])]);

    $this->actingAs($this->admin)->get('/assets/imports')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Asset/Imports/Index')
            ->where('imports.data.0.status', 'done')
            ->where('imports.data.0.created_rows', 1)
            ->where('imports.data.0.user', $this->admin->name));
});

it('fails a file without the required headings', function () {
    $this->actingAs($this->admin)->post('/assets/imports', ['file' => xlsxUpload([['foo', 'bar'], ['1', '2']])]);

    $import = AssetImport::first();
    expect($import->status)->toBe(AssetImport::STATUS_FAILED)
        ->and($import->errors[0]['messages'][0])->toContain('ไฟล์ต้นแบบ');
});

it('rejects a file that is not a spreadsheet', function () {
    $this->actingAs($this->admin)->post('/assets/imports', ['file' => UploadedFile::fake()->create('evil.php', 1)])
        ->assertSessionHasErrors('file');
});

it('keeps an importer whose assets.import reaches only their branch inside it', function () {
    $north = Branch::create(['code' => 'N', 'name' => 'North']);
    Role::create(['name' => 'importer', 'label' => 'Importer', 'guard_name' => 'web']);
    grantTo('importer', ['assets.view', 'assets.import'], 'branch');
    $importer = userWithRole('importer', ['branch_id' => $north->id]);

    $this->actingAs($importer)->post('/assets/imports', ['file' => xlsxUpload([
        HEADINGS,
        [null, 'Mine', 'คอมพิวเตอร์', null, null, null, null, 'x', null],
        [null, 'Not mine', 'คอมพิวเตอร์', 'BKK', null, null, null, 'x', null],
    ])])->assertSessionHasNoErrors();

    $import = AssetImport::first();
    expect($import->created_rows)->toBe(1)
        ->and($import->errors[0]['row'])->toBe(3)
        ->and(Asset::where('name', 'Mine')->value('branch_id'))->toBe($north->id);
});

it('exports the filtered list and imports the same file back as updates', function () {
    createAsset($this->pc, ['name' => 'Spare PC', 'status' => Asset::STATUS_SPARE, 'purchase_price' => 990050, 'specs' => ['cpu' => 'i3', 'ram_gb' => 4]]);
    $busy = createAsset($this->pc, ['name' => 'Busy PC', 'specs' => ['cpu' => 'i9']]);
    app(SaveAsset::class)->handle($busy, ['category_id' => $this->pc->id, 'name' => 'Busy PC', 'status' => 'in_use', 'specs' => ['cpu' => 'i9']], ['SN-1', 'SN-2']);

    $spareOnly = $this->actingAs($this->admin)->get('/assets/export?status=spare')->assertOk();
    $rows = sheetRows($spareOnly->getFile()->getPathname());
    expect($rows[0])->toBe([
        'รหัสทรัพย์สิน', 'ชื่อ', 'หมวด', 'สาขา', 'ลูกค้า (รหัส)', 'ยี่ห้อ', 'รุ่น', 'ประเภทย่อย', 'Serial Number', 'จำนวน', 'หน่วย',
        'เลขครุภัณฑ์', 'สถานะ', 'ตำแหน่งที่ตั้ง', 'ชั้น', 'ห้อง', 'IP Address', 'MAC Address', 'ชื่อผู้ใช้งาน', 'แผนก',
        'วันที่ซื้อ', 'ราคาซื้อ (บาท)', 'วันหมดประกัน', 'หมายเหตุ', 'รหัสเครื่องแม่ (ชุด)', 'สัญญา MA (เลขที่)', 'สถานที่จัดเก็บ', 'CPU [spec.cpu]', 'RAM [spec.ram_gb]',
    ])
        ->and(count($rows))->toBe(2)
        ->and($rows[1][1])->toBe('Spare PC')
        ->and($rows[1][12])->toBe('สำรอง')
        ->and($rows[1][21])->toEqual(9900.5);

    $all = $this->actingAs($this->admin)->get('/assets/export')->assertOk();
    $file = new UploadedFile($all->getFile()->getPathname(), 'export.xlsx', null, null, true);

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => $file])->assertSessionHasNoErrors();

    $import = AssetImport::first();
    expect($import->only(['created_rows', 'updated_rows', 'failed_rows']))->toBe(['created_rows' => 0, 'updated_rows' => 2, 'failed_rows' => 0])
        ->and(Asset::count())->toBe(2)
        ->and(Asset::where('name', 'Spare PC')->first()->only(['status', 'purchase_price', 'specs']))
        ->toBe(['status' => Asset::STATUS_SPARE, 'purchase_price' => 990050, 'specs' => ['cpu' => 'i3', 'ram_gb' => 4]])
        // Several serials share one cell and come back as they were.
        ->and($busy->fresh()->serials->pluck('serial_number')->all())->toBe(['SN-1', 'SN-2']);
});

it('rejects an imported serial number that another asset already has', function () {
    $taken = createAsset($this->pc, ['serial_number' => 'DUP-1', 'specs' => ['cpu' => 'x']]);

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => xlsxUpload([
        ['ชื่อ', 'หมวด', 'Serial Number', 'CPU [spec.cpu]'],
        ['New', 'คอมพิวเตอร์', 'NEW-1, dup-1', 'i5'],
    ])])->assertSessionHasNoErrors();

    $import = AssetImport::first();
    expect($import->failed_rows)->toBe(1)
        ->and($import->errors[0]['messages'])->toBe(["Serial Number dup-1 ซ้ำกับทรัพย์สิน {$taken->asset_code}"])
        ->and(Asset::count())->toBe(1);
});

it('imports floor, room and the asset a row is a part of, by its code or serial number', function () {
    $pc = createAsset($this->pc, ['serial_number' => 'PC-SN-1', 'specs' => ['cpu' => 'x']]);
    $monitors = createAssetCategory(['name' => 'จอภาพ', 'code_prefix' => 'MON']);
    $monitor = createAsset($monitors, ['parent_asset_id' => null]);
    createAsset($monitors, ['name' => 'จอ ชิ้นลูก', 'parent_asset_id' => $pc->id]);

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => xlsxUpload([
        ['รหัสทรัพย์สิน', 'ชื่อ', 'หมวด', 'Serial Number', 'ชั้น', 'ห้อง', 'รหัสเครื่องแม่ (ชุด)', 'CPU [spec.cpu]'],
        [null, 'PC ใหม่', 'คอมพิวเตอร์', 'NEW-PC', '3', 'ประชุม', null, 'i5'],
        [null, 'จอ ของ PC ใหม่', 'จอภาพ', 'NEW-MON', '3', 'ประชุม', 'new-pc', null],
        [$monitor->asset_code, 'จอ เดิม', 'จอภาพ', null, null, null, $pc->asset_code, null],
        [null, 'จอ แม่ไม่มี', 'จอภาพ', null, null, null, 'NOPE', null],
        [null, 'จอ ซ้อนชั้น', 'จอภาพ', null, null, null, 'จอ ชิ้นลูก', null],
        [null, 'จอ แม่เป็นชิ้นลูก', 'จอภาพ', null, null, null, Asset::where('name', 'จอ ชิ้นลูก')->value('asset_code'), null],
    ])]);

    $import = AssetImport::first();
    $newPc = Asset::where('name', 'PC ใหม่')->first();
    expect($import->only(['created_rows', 'updated_rows', 'failed_rows']))->toBe(['created_rows' => 2, 'updated_rows' => 1, 'failed_rows' => 3])
        ->and($newPc->only(['floor', 'room']))->toBe(['floor' => '3', 'room' => 'ประชุม'])
        ->and(Asset::where('name', 'จอ ของ PC ใหม่')->value('parent_asset_id'))->toBe($newPc->id)
        ->and($monitor->fresh()->parent_asset_id)->toBe($pc->id)
        ->and($import->errors[0]['messages'][0])->toContain('NOPE')
        ->and($import->errors[2]['messages'][0])->toContain('ชิ้นส่วนของเครื่องอื่น');
});

it('downloads an empty template with the spec columns', function () {
    $response = $this->actingAs($this->admin)->get('/assets/imports/template')->assertOk();

    $rows = sheetRows($response->getFile()->getPathname());
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toContain('CPU [spec.cpu]', 'RAM [spec.ram_gb]');
});

it('requires assets.import and assets.export', function () {
    $technician = userWithRole('technician');

    $this->actingAs($technician)->get('/assets/imports')->assertForbidden();
    $this->actingAs($technician)->post('/assets/imports', ['file' => xlsxUpload([HEADINGS])])->assertForbidden();
    $this->actingAs($technician)->get('/assets/imports/template')->assertForbidden();
    $this->actingAs($technician)->get('/assets/export')->assertForbidden();
});

it('still reads a renamed heading from an older file', function () {
    expect(AssetSheet::mapHeadings(['ชื่อ', 'ผู้ใช้งานประจำเครื่อง', 'ชื่อผู้ใช้งาน']))
        ->toBe([0 => 'name', 1 => 'used_by', 2 => 'used_by']);
});

it('imports and exports the storage location by name, and refuses an IP or equipment number another asset has', function () {
    $store = StorageLocation::create(['name' => 'คลังหลัก']);
    $taken = createAsset($this->pc, ['ip_address' => '10.0.0.5', 'property_no' => 'P-01', 'specs' => ['cpu' => 'x']]);

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => xlsxUpload([
        ['ชื่อ', 'หมวด', 'IP Address', 'เลขครุภัณฑ์', 'สถานที่จัดเก็บ', 'CPU [spec.cpu]'],
        ['Spare PC', 'คอมพิวเตอร์', null, null, 'คลังหลัก', 'i5'],
        ['Same IP', 'คอมพิวเตอร์', '10.0.0.5', null, null, 'i5'],
        ['Same number', 'คอมพิวเตอร์', null, 'p 01', 'ไม่มีที่นี้', 'i5'],
    ])])->assertSessionHasNoErrors();

    $import = AssetImport::first();
    expect($import->failed_rows)->toBe(2)
        ->and($import->errors[0]['messages'])->toBe(["IP Address ซ้ำกับทรัพย์สิน {$taken->asset_code} {$taken->name}"])
        ->and($import->errors[1]['messages'])->toContain("เลขครุภัณฑ์ซ้ำกับทรัพย์สิน {$taken->asset_code} {$taken->name}", 'ไม่พบสถานที่จัดเก็บ "ไม่มีที่นี้" (ดูที่เมนู ตั้งค่า → สถานที่จัดเก็บ)')
        ->and(Asset::where('name', 'Spare PC')->value('storage_location_id'))->toBe($store->id);

    $rows = sheetRows($this->actingAs($this->admin)->get('/assets/export')->getFile()->getPathname());
    $column = array_search('สถานที่จัดเก็บ', $rows[0], true);
    expect(collect($rows)->firstWhere(1, 'Spare PC')[$column])->toBe('คลังหลัก');
});
