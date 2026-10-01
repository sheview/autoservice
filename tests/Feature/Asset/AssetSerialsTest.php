<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->notebook = createAssetCategory(['name' => 'Notebook', 'code_prefix' => 'NB', 'requires_serial' => true]);
    $this->cable = createAssetCategory(['name' => 'สายและหัวต่อ', 'code_prefix' => 'CB']);
    $this->shared = [
        'category_id' => $this->notebook->id, 'name' => 'Notebook Lenovo', 'brand' => 'Lenovo', 'model' => 'ThinkPad E14',
        'status' => 'spare', 'owner' => 'company', 'location' => 'Stock ชั้น 2',
        'purchased_at' => '2026-05-01', 'purchase_price' => '25900.00', 'warranty_expires_at' => '2029-04-30',
    ];
});

it('keeps several serial numbers on one asset and counts the quantity from them', function () {
    $response = $this->actingAs($this->admin)->post('/assets', [...$this->shared, 'property_no' => 'ครภ.69-001', 'quantity' => 99, 'serials' => [
        ' 225A0Q2000397 ', '', '225A0Q2000396', '   ', '225A0Q2000387',
    ]])->assertSessionHasNoErrors();

    $asset = Asset::sole();
    $response->assertRedirect(route('asset.assets.show', $asset))
        ->assertSessionHas('success', "เพิ่มทรัพย์สิน {$asset->asset_code} เรียบร้อยแล้ว");

    expect($asset->asset_code)->toBe('NB-00001')
        ->and($asset->serials->pluck('serial_number')->all())->toBe(['225A0Q2000397', '225A0Q2000396', '225A0Q2000387'])
        ->and($asset->serial_number)->toBe('225A0Q2000397')
        ->and($asset->quantity)->toBe(3)
        ->and($asset->property_no)->toBe('ครภ.69-001');

    $this->actingAs($this->admin)->get("/assets/{$asset->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('asset.serials', ['225A0Q2000397', '225A0Q2000396', '225A0Q2000387'])
        ->where('asset.quantity', 3));

    // found by any of its serials
    $this->actingAs($this->admin)->get('/assets?search=2000387')->assertInertia(fn (Assert $page) => $page->where('assets.total', 1));
});

it('requires at least one serial number for a device category', function () {
    $this->actingAs($this->admin)->post('/assets', [...$this->shared, 'serials' => ['', ' ']])
        ->assertSessionHasErrors(['serials' => 'หมวด Notebook ต้องกรอก Serial Number อย่างน้อย 1 รายการ']);
    $this->actingAs($this->admin)->post('/assets', $this->shared)->assertSessionHasErrors('serials');

    expect(Asset::count())->toBe(0);
});

it('stores things without serial as one record of a lot, with quantity and unit', function () {
    $lot = ['category_id' => $this->cable->id, 'name' => 'หัวแลน CAT6', 'status' => 'spare', 'owner' => 'company', 'location' => 'Stock'];

    $this->actingAs($this->admin)->post('/assets', [...$lot, 'quantity' => 50, 'unit' => 'ตัว'])->assertSessionHasNoErrors();
    expect(Asset::sole()->only(['quantity', 'unit', 'serial_number']))->toBe(['quantity' => 50, 'unit' => 'ตัว', 'serial_number' => null]);

    // 1 when not sent; whole numbers from 1 only; never fewer than the serials listed
    $this->actingAs($this->admin)->post('/assets', $lot)->assertSessionHasNoErrors();
    expect(Asset::latest('id')->first()->quantity)->toBe(1);

    $this->actingAs($this->admin)->post('/assets', [...$lot, 'quantity' => 0])->assertSessionHasErrors('quantity');
    $this->actingAs($this->admin)->post('/assets', [...$lot, 'quantity' => 2.5])->assertSessionHasErrors('quantity');
    $this->actingAs($this->admin)->post('/assets', [...$lot, 'quantity' => 1, 'serials' => ['A', 'B']])
        ->assertSessionHasErrors(['quantity' => 'จำนวนต้องไม่น้อยกว่าจำนวน Serial Number ที่กรอก (2)']);

    expect(Asset::count())->toBe(2);
});

it('refuses a serial number another asset has, saying which, and repeats in the form', function () {
    $other = createAsset($this->notebook, ['serial_number' => 'SN-TAKEN']);

    $this->actingAs($this->admin)->post('/assets', [...$this->shared, 'serials' => ['SN-NEW', 'sn-taken']])
        ->assertSessionHasErrors(['serials.1' => "Serial Number sn-taken ซ้ำกับทรัพย์สิน {$other->asset_code}"]);
    $this->actingAs($this->admin)->post('/assets', [...$this->shared, 'serials' => ['SN-A', 'sn-a']])
        ->assertSessionHasErrors('serials.1');
    expect(Asset::count())->toBe(1);

    // an edit keeps its own serials, and can drop one and add another
    $this->actingAs($this->admin)->put("/assets/{$other->ulid}", [...$this->shared, 'serials' => ['SN-TAKEN', 'SN-SECOND']])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put("/assets/{$other->ulid}", [...$this->shared, 'serials' => ['SN-SECOND', 'SN-THIRD']])
        ->assertSessionHasNoErrors();
    expect($other->fresh()->serials->pluck('serial_number')->all())->toBe(['SN-SECOND', 'SN-THIRD'])
        ->and($other->fresh()->serial_number)->toBe('SN-SECOND')
        ->and($other->fresh()->quantity)->toBe(2);

    // the serial a deleted asset had can be registered again
    $this->actingAs($this->admin)->delete("/assets/{$other->ulid}")->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/assets', [...$this->shared, 'serials' => ['SN-THIRD']])->assertSessionHasNoErrors();
});

it('does not see the serial numbers of another company', function () {
    asTenant(createTenant('other'), fn () => createAsset(createAssetCategory(), ['serial_number' => 'SAME-SN']));

    $this->actingAs($this->admin)->post('/assets', [...$this->shared, 'serials' => ['SAME-SN']])->assertSessionHasNoErrors();
    expect(Asset::sole()->serial_number)->toBe('SAME-SN');
});

it('needs a branch or a location, and an owner', function () {
    $branch = Branch::create(['code' => 'BKK', 'name' => 'กรุงเทพ']);
    $base = [...$this->shared, 'serials' => ['X1']];

    $this->actingAs($this->admin)->post('/assets', [...$base, 'location' => null, 'branch_id' => null])
        ->assertSessionHasErrors(['branch_id' => 'กรุณาเลือกสาขา หรือกรอกตำแหน่งที่ตั้ง อย่างน้อยหนึ่งอย่าง']);
    $this->actingAs($this->admin)->post('/assets', [...$base, 'owner' => null])->assertSessionHasErrors('owner');
    $this->actingAs($this->admin)->post('/assets', [...$base, 'owner' => 'customer'])->assertSessionHasErrors('customer_id');

    $this->actingAs($this->admin)->post('/assets', [...$base, 'location' => null, 'branch_id' => $branch->id])->assertSessionHasNoErrors();
    expect(Asset::sole()->only(['branch_id', 'customer_id']))->toBe(['branch_id' => $branch->id, 'customer_id' => null]);
});

it('owner "company" leaves no customer, owner "customer" needs one', function () {
    $customer = createCustomer();

    $this->actingAs($this->admin)->post('/assets', [...$this->shared, 'serials' => ['C1'], 'owner' => 'company', 'customer_id' => $customer->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/assets', [...$this->shared, 'serials' => ['C2'], 'owner' => 'customer', 'customer_id' => $customer->id])
        ->assertSessionHasNoErrors();

    expect(Asset::orderBy('id')->pluck('customer_id')->all())->toBe([null, $customer->id]);
    $this->actingAs($this->admin)->get('/assets/'.Asset::latest('id')->first()->ulid.'/edit')
        ->assertInertia(fn (Assert $page) => $page->where('asset.owner', 'customer')->where('asset.serials', ['C2']));
});

it('needs a warranty date with a purchase date, not before it', function () {
    $base = [...$this->shared, 'serials' => ['W1']];

    $this->actingAs($this->admin)->post('/assets', [...$base, 'warranty_expires_at' => null])
        ->assertSessionHasErrors(['warranty_expires_at' => 'กรุณากรอกวันหมดประกัน เมื่อระบุวันที่ซื้อ']);
    $this->actingAs($this->admin)->post('/assets', [...$base, 'warranty_expires_at' => '2026-04-30'])
        ->assertSessionHasErrors(['warranty_expires_at' => 'วันหมดประกันต้องไม่ก่อนวันที่ซื้อ']);
    $this->actingAs($this->admin)->post('/assets', [...$base, 'purchased_at' => null, 'warranty_expires_at' => null])->assertSessionHasNoErrors();
});

it('registers an asset that is already issued or lent, with who has it and since when', function () {
    $base = [...$this->shared, 'serials' => ['OUT-1']];

    $this->actingAs($this->admin)->post('/assets', [...$base, 'status' => 'loaned'])
        ->assertSessionHasErrors(['holder_name' => 'สถานะถูกเบิกหรือถูกยืม ต้องกรอกผู้ใช้งานและวันที่', 'handed_out_on']);
    $this->actingAs($this->admin)->post('/assets', [...$base, 'status' => 'loaned', 'holder_name' => 'สมชาย', 'handed_out_on' => now()->addDay()->toDateString()])
        ->assertSessionHasErrors('handed_out_on');

    $this->actingAs($this->admin)->post('/assets', [...$base, 'status' => 'loaned', 'holder_name' => 'สมชาย ใจดี', 'handed_out_on' => '2026-09-15'])
        ->assertSessionHasNoErrors();

    $asset = Asset::sole();
    $checkout = AssetCheckout::sole();
    expect($asset->status)->toBe(Asset::STATUS_IN_USE)
        ->and($checkout->only(['asset_id', 'type', 'status', 'borrower_name']))
        ->toBe(['asset_id' => $asset->id, 'type' => 'loan', 'status' => 'approved', 'borrower_name' => 'สมชาย ใจดี'])
        ->and($checkout->decided_at->toDateString())->toBe('2026-09-15');

    // only when creating: an asset already registered is handed out through a checkout form
    $this->actingAs($this->admin)->put("/assets/{$asset->ulid}", [...$base, 'status' => 'issued', 'holder_name' => 'X', 'handed_out_on' => '2026-09-15'])
        ->assertSessionHasErrors('status');
    $this->actingAs($this->admin)->get("/assets/{$asset->ulid}/edit")->assertInertia(fn (Assert $page) => $page->where('handedOutStatuses', []));
});

it('offers the brands in use with how often, and the sub-types', function () {
    createAsset($this->notebook, ['brand' => 'Cisco', 'subtype' => 'Switching HUB']);
    createAsset($this->notebook, ['brand' => 'Cisco']);
    createAsset($this->notebook, ['brand' => 'Ciso']);

    $this->actingAs($this->admin)->get('/assets/create')->assertInertia(fn (Assert $page) => $page
        ->where('brands', [['name' => 'Cisco', 'count' => 2], ['name' => 'Ciso', 'count' => 1]])
        ->where('subtypes', ['Switching HUB'])
        ->where('handedOutStatuses', ['issued', 'loaned'])
        ->where('categories.0.requires_serial', true));
});

it('marks a category as requiring serial numbers', function () {
    $this->actingAs($this->admin)->put("/asset-categories/{$this->cable->id}", [
        'name' => $this->cable->name, 'code_prefix' => 'CB', 'requires_serial' => true,
    ])->assertSessionHasNoErrors();

    expect($this->cable->fresh()->requires_serial)->toBeTrue();

    // left out (an older client): it stays as it was
    $this->actingAs($this->admin)->put("/asset-categories/{$this->cable->id}", ['name' => $this->cable->name, 'code_prefix' => 'CB'])
        ->assertSessionHasNoErrors();
    expect($this->cable->fresh()->requires_serial)->toBeTrue()
        ->and(AssetCategory::find($this->notebook->id)->requires_serial)->toBeTrue();
});

it('lists the devices of the same model on the asset page, within what the user may see', function () {
    $north = Branch::create(['code' => 'N', 'name' => 'North']);
    $south = Branch::create(['code' => 'S', 'name' => 'South']);
    $tech = userWithRole('technician', ['branch_id' => $north->id]);
    $mine = createAsset($this->notebook, ['brand' => 'Lenovo', 'model' => 'ThinkPad E14', 'serial_number' => 'A', 'status' => 'in_use', 'branch_id' => $north->id]);
    createAsset($this->notebook, ['brand' => ' lenovo ', 'model' => 'thinkpad e14', 'serial_number' => 'B', 'status' => 'spare', 'branch_id' => $north->id]);
    createAsset($this->notebook, ['brand' => 'Lenovo', 'model' => 'ThinkPad E14', 'serial_number' => 'C', 'status' => 'spare', 'branch_id' => $south->id]);
    createAsset($this->notebook, ['brand' => 'Lenovo', 'model' => 'ThinkPad T14', 'serial_number' => 'other model']);
    createAsset(createAssetCategory(), ['brand' => 'Lenovo', 'model' => 'ThinkPad E14', 'serial_number' => 'other category']);

    $this->actingAs($this->admin)->get("/assets/{$mine->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('sameModel', fn ($units) => collect($units)->pluck('serial_number')->sort()->values()->all() === ['A', 'B', 'C'])
        ->where('can.create', true));

    $this->actingAs($tech)->get("/assets/{$mine->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('sameModel', fn ($units) => collect($units)->pluck('serial_number')->sort()->values()->all() === ['A', 'B']));

    // no brand and no model: nothing says which devices are the same
    $plain = createAsset($this->notebook);
    $this->actingAs($this->admin)->get("/assets/{$plain->ulid}")->assertInertia(fn (Assert $page) => $page->where('sameModel', []));
});

it('starts a new asset from a copy of one of the same model', function () {
    $source = createAsset($this->notebook, ['brand' => 'Lenovo', 'model' => 'ThinkPad E14', 'serial_number' => 'A', 'property_no' => 'P-1', 'location' => 'ชั้น 3']);

    $this->actingAs($this->admin)->get("/assets/create?from={$source->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('Asset/Assets/Form')
        ->where('copy.asset_code', $source->asset_code)
        ->where('copy.model', 'ThinkPad E14')
        ->where('copy.location', 'ชั้น 3')
        ->missing('copy.serials')
        ->missing('copy.property_no')
        ->missing('copy.ulid'));

    // another company's asset is not found, so nothing is copied
    $other = asTenant(createTenant('other'), fn () => createAsset(createAssetCategory(), ['brand' => 'Secret']));
    $this->actingAs($this->admin)->get("/assets/create?from={$other->ulid}")->assertInertia(fn (Assert $page) => $page->where('copy', null));
});

it('finds an asset by its equipment number', function () {
    createAsset($this->notebook, ['name' => 'Found', 'property_no' => 'ครภ.69-777']);
    createAsset($this->notebook, ['name' => 'Other']);

    $this->actingAs($this->admin)->get('/assets?search=69-777')->assertInertia(fn (Assert $page) => $page
        ->where('assets.total', 1)
        ->where('assets.data.0.property_no', 'ครภ.69-777'));
});

it('exports and imports the equipment number, and refuses one used twice', function () {
    Storage::fake('local');
    createAsset($this->notebook, ['name' => 'Exported', 'property_no' => 'P-EXP']);

    $export = $this->actingAs($this->admin)->get('/assets/export');
    $rows = Excel::toCollection(null, $export->baseResponse->getFile()->getPathname())->first()->toArray();
    $column = array_search('เลขครุภัณฑ์', $rows[0], true);
    expect($column)->not->toBeFalse()->and($rows[1][$column])->toBe('P-EXP');

    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['ชื่อ', 'หมวด', 'Serial Number', 'เลขครุภัณฑ์'],
        ['New one', 'Notebook', 'S-1', 'P-NEW'],
        ['Twice', 'Notebook', 'S-2', 'P-NEW'],
        ['Taken', 'Notebook', 'S-3', 'P-EXP'],
    ], null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'assets').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => new UploadedFile($path, 'assets.xlsx', null, null, true)])
        ->assertSessionHasNoErrors();

    $import = AssetImport::sole();
    expect($import->only(['created_rows', 'failed_rows']))->toBe(['created_rows' => 1, 'failed_rows' => 2])
        ->and(collect($import->errors)->pluck('row')->all())->toBe([3, 4])
        ->and(Asset::where('name', 'New one')->value('property_no'))->toBe('P-NEW');
});
