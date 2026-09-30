<?php

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Store keeper']);
});

/**
 * An uploaded .xlsx file with these rows (first row = headings).
 */
function partsXlsx(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'parts').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'parts.xlsx', null, null, true);
}

function partSheetRows(string $path): array
{
    return Excel::toCollection(null, $path)->first()->toArray();
}

const PART_HEADINGS = ['รหัสอะไหล่', 'ชื่ออะไหล่', 'ยี่ห้อ', 'หน่วยนับ', 'จุดสั่งซื้อ', 'ราคาต่อหน่วย (บาท)', 'คงเหลือ', 'สถานะ'];

it('imports new parts with opening stock, updates existing codes and reports bad rows by row number', function () {
    $ram = createPart(['code' => 'RAM', 'name' => 'Old name', 'brand' => 'Old brand', 'notes' => 'keep me'], stock: 3);

    $this->actingAs($this->admin)->post('/parts/import', ['file' => partsXlsx([
        PART_HEADINGS,
        ['ram', 'Memory 8GB', 'Kingston', 'ชิ้น', 2, '1,250.50', 99, 'ปิดใช้งาน'],
        ['SSD-500', 'SSD 500GB', null, 'ชิ้น', null, 1490, 7, null],
        [12345, 'Numeric code', null, 'pcs', null, null, null, 'active'],
        ['bad code', 'Bad code', null, 'pcs', null, null, null, null],
        ['NOUNIT', 'No unit', null, null, -1, null, null, 'maybe'],
        [null, null, null, null, null, null, null, null],
    ])])->assertOk()->assertInertia(fn (Assert $page) => $page->component('Inventory/Parts/Import')
        ->where('result.ok', true)
        ->where('result.total', 5)
        ->where('result.created', 2)
        ->where('result.updated', 1)
        ->where('result.failed', 2)
        ->where('result.errors.0.row', 5)
        ->where('result.errors.1.row', 6)
        ->where('result.errors.1.messages', fn ($messages) => collect($messages)->contains('ไม่รู้จักสถานะ "maybe"')));

    // the existing part: data updated, stock untouched, columns missing from the file kept
    expect($ram->fresh()->only(['code', 'name', 'brand', 'unit', 'min_qty', 'unit_cost', 'qty_on_hand', 'is_active', 'notes']))->toBe([
        'code' => 'RAM', 'name' => 'Memory 8GB', 'brand' => 'Kingston', 'unit' => 'ชิ้น', 'min_qty' => 2, 'unit_cost' => 125050,
        'qty_on_hand' => 3, 'is_active' => false, 'notes' => 'keep me',
    ]);

    // the new part: opening stock received through the ledger
    $ssd = Part::where('code', 'SSD-500')->first();
    expect($ssd->only(['qty_on_hand', 'unit_cost', 'min_qty', 'is_active']))
        ->toBe(['qty_on_hand' => 7, 'unit_cost' => 149000, 'min_qty' => 0, 'is_active' => true])
        ->and($ssd->movements()->first()->only(['type', 'quantity', 'balance_after', 'unit_cost', 'user_name']))
        ->toBe(['type' => 'receive', 'quantity' => 7, 'balance_after' => 7, 'unit_cost' => 149000, 'user_name' => 'Store keeper'])
        ->and(Part::where('code', '12345')->value('qty_on_hand'))->toBe(0)
        ->and(Part::count())->toBe(3)
        ->and(StockMovement::count())->toBe(2);
});

it('fails a file without the required headings or that is not a spreadsheet', function () {
    $this->actingAs($this->admin)->post('/parts/import', ['file' => partsXlsx([['foo', 'bar'], ['1', '2']])])
        ->assertInertia(fn (Assert $page) => $page->where('result.ok', false)
            ->where('result.errors.0.messages.0', fn ($message) => str_contains($message, 'ไฟล์ต้นแบบ')));

    $this->actingAs($this->admin)->post('/parts/import', ['file' => UploadedFile::fake()->create('evil.php', 1)])
        ->assertSessionHasErrors('file');

    expect(Part::count())->toBe(0);
});

it('refuses the code of a deleted part', function () {
    $gone = createPart(['code' => 'GONE']);
    $gone->delete();

    $this->actingAs($this->admin)->post('/parts/import', ['file' => partsXlsx([PART_HEADINGS, ['GONE', 'Back again', null, 'pcs', null, null, 5, null]])])
        ->assertInertia(fn (Assert $page) => $page->where('result.failed', 1)->where('result.created', 0));

    expect(Part::count())->toBe(0);
});

it('exports the filtered list and imports the same file back as updates', function () {
    createPart(['code' => 'RAM', 'name' => 'Memory', 'brand' => 'Kingston', 'part_number' => 'KVR', 'min_qty' => 2, 'unit_cost' => 125050, 'notes' => 'ชั้น A'], stock: 4);
    createPart(['code' => 'PSU', 'name' => 'Power supply', 'is_active' => false]);

    $inactiveOnly = $this->actingAs($this->admin)->get('/parts/export?status=inactive')->assertOk();
    $rows = partSheetRows($inactiveOnly->getFile()->getPathname());
    expect($rows[0])->toBe(['รหัสอะไหล่', 'ชื่ออะไหล่', 'ยี่ห้อ', 'Part number', 'หน่วยนับ', 'จุดสั่งซื้อ', 'ราคาต่อหน่วย (บาท)', 'คงเหลือ', 'สถานะ', 'หมายเหตุ'])
        ->and(count($rows))->toBe(2)
        ->and($rows[1][0])->toBe('PSU')
        ->and($rows[1][8])->toBe('ปิดใช้งาน');

    $all = $this->actingAs($this->admin)->get('/parts/export')->assertOk();
    $rows = partSheetRows($all->getFile()->getPathname());
    expect($rows[2][0])->toBe('RAM')
        ->and($rows[2][6])->toEqual(1250.5)
        ->and($rows[2][7])->toEqual(4);

    $file = new UploadedFile($all->getFile()->getPathname(), 'export.xlsx', null, null, true);
    $this->actingAs($this->admin)->post('/parts/import', ['file' => $file])
        ->assertInertia(fn (Assert $page) => $page->where('result.created', 0)->where('result.updated', 2)->where('result.failed', 0));

    expect(Part::where('code', 'RAM')->first()->only(['name', 'brand', 'part_number', 'min_qty', 'unit_cost', 'qty_on_hand', 'is_active', 'notes']))->toBe([
        'name' => 'Memory', 'brand' => 'Kingston', 'part_number' => 'KVR', 'min_qty' => 2, 'unit_cost' => 125050,
        'qty_on_hand' => 4, 'is_active' => true, 'notes' => 'ชั้น A',
    ])
        ->and(StockMovement::count())->toBe(1);
});

it('downloads an empty template', function () {
    $response = $this->actingAs($this->admin)->get('/parts/import/template')->assertOk();

    $rows = partSheetRows($response->getFile()->getPathname());
    expect($rows)->toHaveCount(1)->and($rows[0])->toContain('รหัสอะไหล่', 'คงเหลือ');
});

it('requires part.import and part.export, and imports only into the own tenant', function () {
    $technician = userWithRole('technician');

    $this->actingAs($technician)->get('/parts/import')->assertForbidden();
    $this->actingAs($technician)->post('/parts/import', ['file' => partsXlsx([PART_HEADINGS])])->assertForbidden();
    $this->actingAs($technician)->get('/parts/import/template')->assertForbidden();
    $this->actingAs($technician)->get('/parts/export')->assertForbidden();
    $this->actingAs($technician)->get('/parts')->assertInertia(fn (Assert $page) => $page->where('can.import', false)->where('can.export', false));
    $this->actingAs($this->admin)->get('/parts/import')->assertInertia(fn (Assert $page) => $page->where('result', null));

    // the same code in another tenant is not touched: the row creates a part here
    $other = createTenant('other');
    asTenant($other, fn () => createPart(['code' => 'RAM', 'name' => 'Their memory'], stock: 9));

    $this->actingAs($this->admin)->post('/parts/import', ['file' => partsXlsx([PART_HEADINGS, ['RAM', 'My memory', null, 'pcs', null, null, 1, null]])])
        ->assertInertia(fn (Assert $page) => $page->where('result.created', 1)->where('result.updated', 0));

    expect(Part::pluck('name')->all())->toBe(['My memory']);
    asTenant($other, fn () => expect(Part::first()->only(['name', 'qty_on_hand']))->toBe(['name' => 'Their memory', 'qty_on_hand' => 9]));

    $export = partSheetRows($this->actingAs($this->admin)->get('/parts/export')->getFile()->getPathname());
    expect(array_column(array_slice($export, 1), 1))->toBe(['My memory']);
});
