<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    Storage::fake('local');

    $this->admin = userWithRole('admin_company');
    $this->category = createAssetCategory(['name' => 'สวิตช์', 'code_prefix' => 'SW']);
    $this->acme = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $this->other = createCustomer(['code' => 'OTHER', 'name' => 'Other']);
    $this->contract = createContract($this->acme, ['contract_no' => 'MA-ACME', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addYear()->toDateString()]);
    $this->otherContract = createContract($this->other, ['contract_no' => 'MA-OTHER', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addYear()->toDateString()]);
});

/** An uploaded .xlsx file with these rows (first row = headings). */
function importFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'assets').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'assets.xlsx', null, null, true);
}

it('offers the customers and their MA contracts on the import page', function () {
    $this->actingAs($this->admin)->get('/assets/imports')->assertInertia(fn (Assert $page) => $page
        ->has('customers', 2)
        ->where("contracts.{$this->acme->id}.0.id", $this->contract->id)
        ->where("contracts.{$this->other->id}.0.id", $this->otherContract->id));
});

it('puts every row of the file to the chosen customer and MA contract', function () {
    $this->actingAs($this->admin)->post('/assets/imports', [
        'file' => importFile([
            ['ชื่อ', 'หมวด', 'ลูกค้า (รหัส)', 'ชื่อผู้ใช้งาน'],
            ['Core switch', 'สวิตช์', null, 'สมชาย'],
            ['Access switch', 'สวิตช์', 'OTHER', null],
        ]),
        'customer_id' => $this->acme->id,
        'contract_id' => $this->contract->id,
    ])->assertSessionHasNoErrors();

    $import = AssetImport::latest('id')->first();
    expect($import->status)->toBe(AssetImport::STATUS_DONE)
        ->and($import->created_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(1); // a row of another customer cannot go into Acme's contract

    $asset = Asset::where('name', 'Core switch')->first();
    expect($asset->customer_id)->toBe($this->acme->id)
        ->and($asset->used_by)->toBe('สมชาย')
        ->and($this->contract->contractAssets()->where('asset_id', $asset->id)->exists())->toBeTrue();
});

it('sets the customer of rows without one when only a customer is chosen', function () {
    $this->actingAs($this->admin)->post('/assets/imports', [
        'file' => importFile([['ชื่อ', 'หมวด'], ['Core switch', 'สวิตช์']]),
        'customer_id' => $this->acme->id,
    ])->assertSessionHasNoErrors();

    $asset = Asset::where('name', 'Core switch')->first();
    expect($asset->customer_id)->toBe($this->acme->id)
        ->and($this->contract->contractAssets()->where('asset_id', $asset->id)->exists())->toBeFalse();
});

it('refuses a contract of another customer or without a customer', function () {
    $file = fn () => importFile([['ชื่อ', 'หมวด'], ['Core switch', 'สวิตช์']]);

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => $file(), 'customer_id' => $this->acme->id, 'contract_id' => $this->otherContract->id])
        ->assertSessionHasErrors('contract_id');
    $this->actingAs($this->admin)->post('/assets/imports', ['file' => $file(), 'contract_id' => $this->contract->id])
        ->assertSessionHasErrors('customer_id');

    expect(AssetImport::count())->toBe(0);
});

it('cannot choose a customer or contract of another tenant', function () {
    [$theirCustomer, $theirContract] = asTenant(createTenant('other'), function () {
        $customer = createCustomer(['code' => 'THEIRS']);

        return [$customer, createContract($customer)];
    });

    $this->actingAs($this->admin)->post('/assets/imports', [
        'file' => importFile([['ชื่อ', 'หมวด'], ['Core switch', 'สวิตช์']]),
        'customer_id' => $theirCustomer->id,
        'contract_id' => $theirContract->id,
    ])->assertSessionHasErrors('customer_id');

    $this->actingAs($this->admin)->get('/assets/imports')->assertInertia(fn (Assert $page) => $page->has('customers', 2));
});
