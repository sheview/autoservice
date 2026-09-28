<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImport;
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
        ->and($import->errors[0]['messages'])->toBe(['ไม่พบหมวด "ไม่มีหมวดนี้"'])
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

it('keeps an importer without branch.all inside their branch', function () {
    $north = Branch::create(['code' => 'N', 'name' => 'North']);
    $role = Role::create(['name' => 'importer', 'label' => 'Importer', 'guard_name' => 'web']);
    $role->syncPermissions(['asset.view', 'asset.import']);
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
    createAsset($this->pc, ['name' => 'Busy PC', 'specs' => ['cpu' => 'i9']]);

    $spareOnly = $this->actingAs($this->admin)->get('/assets/export?status=spare')->assertOk();
    $rows = sheetRows($spareOnly->getFile()->getPathname());
    expect($rows[0])->toBe([
        'รหัสทรัพย์สิน', 'ชื่อ', 'หมวด', 'สาขา', 'ยี่ห้อ', 'รุ่น', 'Serial Number', 'สถานะ', 'ตำแหน่งที่ตั้ง',
        'วันที่ซื้อ', 'ราคาซื้อ (บาท)', 'วันหมดประกัน', 'หมายเหตุ', 'CPU [spec.cpu]', 'RAM [spec.ram_gb]',
    ])
        ->and(count($rows))->toBe(2)
        ->and($rows[1][1])->toBe('Spare PC')
        ->and($rows[1][7])->toBe('สำรอง')
        ->and($rows[1][10])->toEqual(9900.5);

    $all = $this->actingAs($this->admin)->get('/assets/export')->assertOk();
    $file = new UploadedFile($all->getFile()->getPathname(), 'export.xlsx', null, null, true);

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => $file])->assertSessionHasNoErrors();

    $import = AssetImport::first();
    expect($import->only(['created_rows', 'updated_rows', 'failed_rows']))->toBe(['created_rows' => 0, 'updated_rows' => 2, 'failed_rows' => 0])
        ->and(Asset::count())->toBe(2)
        ->and(Asset::where('name', 'Spare PC')->first()->only(['status', 'purchase_price', 'specs']))
        ->toBe(['status' => Asset::STATUS_SPARE, 'purchase_price' => 990050, 'specs' => ['cpu' => 'i3', 'ram_gb' => 4]]);
});

it('downloads an empty template with the spec columns', function () {
    $response = $this->actingAs($this->admin)->get('/assets/imports/template')->assertOk();

    $rows = sheetRows($response->getFile()->getPathname());
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toContain('CPU [spec.cpu]', 'RAM [spec.ram_gb]');
});

it('requires asset.import and asset.export', function () {
    $technician = userWithRole('technician');

    $this->actingAs($technician)->get('/assets/imports')->assertForbidden();
    $this->actingAs($technician)->post('/assets/imports', ['file' => xlsxUpload([HEADINGS])])->assertForbidden();
    $this->actingAs($technician)->get('/assets/imports/template')->assertForbidden();
    $this->actingAs($technician)->get('/assets/export')->assertForbidden();
});
