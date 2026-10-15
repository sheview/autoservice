<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Contract\Actions\AddContractAssets;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->customer = createCustomer(['code' => 'ACME', 'name' => 'Acme']);
    $this->contract = createContract($this->customer, ['contract_no' => 'MA-1']);
    $this->category = createAssetCategory();

    $this->mine = createAsset($this->category, ['name' => 'Acme Switch', 'customer_id' => $this->customer->id]);
    $this->alsoMine = createAsset($this->category, ['name' => 'Acme Router', 'customer_id' => $this->customer->id]);
    $this->otherCustomers = createAsset($this->category, ['name' => 'Beta PC', 'customer_id' => createCustomer()->id]);
});

it('saves the customer of an asset and filters assets by customer', function () {
    $this->actingAs($this->admin)->get("/assets?customer_id={$this->customer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('assets.total', 2)
            ->where('assets.data.0.customer', 'Acme')
            ->where('customers', fn ($customers) => collect($customers)->pluck('code')->contains('ACME')));
});

it('finds candidates of the contract customer only, then adds and removes them', function () {
    $this->actingAs($this->admin)->get("/contracts/{$this->contract->id}?asset_search=acme")
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidates', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Acme Router', 'Acme Switch']));

    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->mine->id, $this->alsoMine->id]])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get("/contracts/{$this->contract->id}?asset_search=acme")
        ->assertInertia(fn (Assert $page) => $page
            ->where('assetCount', 2)
            ->where('assets', fn ($rows) => count($rows) === 2)
            ->where('candidates', []));

    $this->actingAs($this->admin)->delete("/contracts/{$this->contract->id}/assets/{$this->mine->id}")->assertSessionHasNoErrors();

    expect($this->contract->contractAssets()->pluck('asset_id')->all())->toBe([$this->alsoMine->id]);
});

it('does not add an asset of another customer or twice', function () {
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->otherCustomers->id]])
        ->assertSessionHasErrors('asset_ids');

    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->mine->id]]);
    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->mine->id]]);

    expect($this->contract->contractAssets()->count())->toBe(1);
});

it('shows on the asset page which contracts cover it', function () {
    $renewal = createContract($this->customer, [
        'contract_no' => 'MA-2', 'starts_on' => now()->addMonths(11)->addDay()->toDateString(), 'ends_on' => now()->addMonths(23)->toDateString(),
    ]);
    $this->contract->contractAssets()->create(['asset_id' => $this->mine->id]);
    $renewal->contractAssets()->create(['asset_id' => $this->mine->id]);

    $this->actingAs($this->admin)->get("/assets/{$this->mine->ulid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('asset.customer', 'Acme')
            ->where('contracts.0.contract_no', 'MA-2')
            ->where('contracts.0.phase', 'upcoming')
            ->where('contracts.0.covering', false)
            ->where('contracts.1.contract_no', 'MA-1')
            ->where('contracts.1.covering', true));

    // no contracts.view -> no contract section
    grantTo('user', ['assets.view']);
    $this->actingAs(userWithRole('user'))->get("/assets/{$this->mine->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('contracts', null));
});

it('hides covered assets of other branches from a user with branch scope on assets', function () {
    $north = Branch::create(['code' => 'N', 'name' => 'North']);
    $south = Branch::create(['code' => 'S', 'name' => 'South']);
    $this->mine->update(['branch_id' => $north->id]);
    $this->alsoMine->update(['branch_id' => $south->id]);
    $this->contract->contractAssets()->create(['asset_id' => $this->mine->id]);
    $this->contract->contractAssets()->create(['asset_id' => $this->alsoMine->id]);

    // the technician works on a ticket of this customer, so its contracts are theirs to see (scope own)
    $technician = userWithRole('technician', ['branch_id' => $north->id]);
    openTicket($this->admin, ['customer_id' => $this->customer->id, 'assignee_id' => $technician->id]);

    $this->actingAs($technician)->get("/contracts/{$this->contract->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('assetCount', 2)
            ->where('assets', fn ($rows) => collect($rows)->pluck('name')->all() === ['Acme Switch'])
            ->where('can.manageAssets', false));
});

it('keeps asset exports and imports in step with the customer column', function () {
    $response = $this->actingAs($this->admin)->get('/assets/export?search=Acme+Switch')->assertOk();
    $rows = Excel::toCollection(null, $response->getFile()->getPathname())->first();

    expect($rows[1][4])->toBe('ACME');
});

it('puts an asset into a running contract of its customer from the asset page, and takes it out', function () {
    $ended = createContract($this->customer, ['contract_no' => 'MA-OLD', 'starts_on' => now()->subYears(2)->toDateString(), 'ends_on' => now()->subYear()->toDateString()]);
    $draft = createContract($this->customer, ['contract_no' => 'MA-DRAFT', 'status' => 'draft']);
    createContract(createCustomer(), ['contract_no' => 'MA-BETA']);
    app(AddContractAssets::class)->handle($ended, [$this->mine->id]);

    // Offers only running contracts of its own customer that it is not in; the ended one it is in may be left.
    $this->actingAs($this->admin)->get("/assets/{$this->mine->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('contractEdit.joinable', fn ($rows) => collect($rows)->pluck('contract_no')->all() === ['MA-1'])
        ->where('contractEdit.removable', [$ended->id]));

    $this->actingAs($this->admin)->post("/contracts/{$this->contract->id}/assets", ['asset_ids' => [$this->mine->id]])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->delete("/contracts/{$ended->id}/assets/{$this->mine->id}")->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get("/assets/{$this->mine->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('contracts', fn ($rows) => collect($rows)->pluck('contract_no')->all() === ['MA-1'])
        ->where('contractEdit.joinable', [])
        ->where('contractEdit.removable', [$this->contract->id]));
    expect($draft->contractAssets()->count())->toBe(0);

    // Who may only look at contracts sees them, without the controls.
    $this->actingAs(userWithRole('helpdesk'))->get("/assets/{$this->mine->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('contracts', fn ($rows) => count($rows) === 1)
        ->where('contractEdit', null));
    // A company asset has no customer, so no contract to offer.
    $own = createAsset($this->category, ['name' => 'Our PC']);
    $this->actingAs($this->admin)->get("/assets/{$own->ulid}")->assertInertia(fn (Assert $page) => $page->where('contractEdit', null));
});

it('puts a new asset into the contracts ticked on the form, only running ones of its customer', function () {
    $beta = createCustomer(['code' => 'BETA']);
    $betaContract = createContract($beta, ['contract_no' => 'MA-BETA']);
    $ended = createContract($this->customer, ['contract_no' => 'MA-OLD', 'starts_on' => now()->subYears(2)->toDateString(), 'ends_on' => now()->subYear()->toDateString()]);
    $payload = fn (array $contractIds) => [
        'category_id' => $this->category->id, 'name' => 'New Acme AP', 'status' => 'in_use', 'owner' => 'customer',
        'customer_id' => $this->customer->id, 'location' => 'ชั้น 2', 'contract_ids' => $contractIds,
    ];

    $this->actingAs($this->admin)->get('/assets/create')->assertInertia(fn (Assert $page) => $page
        ->where("customerContracts.{$this->customer->id}", fn ($rows) => collect($rows)->pluck('contract_no')->all() === ['MA-1']));

    // Another customer's contract, an ended one: refused, and nothing is created.
    foreach ([$betaContract->id, $ended->id] as $id) {
        $this->actingAs($this->admin)->post('/assets', $payload([$id]))->assertSessionHasErrors('contract_ids');
    }
    expect(Asset::where('name', 'New Acme AP')->exists())->toBeFalse();

    $this->actingAs($this->admin)->post('/assets', $payload([$this->contract->id]))->assertSessionHasNoErrors();
    $asset = Asset::where('name', 'New Acme AP')->sole();
    expect($this->contract->contractAssets()->pluck('asset_id')->all())->toBe([$asset->id]);

    // Editing does not take contracts: those are changed on the asset or contract page.
    $this->actingAs($this->admin)->put("/assets/{$asset->ulid}", $payload([$this->contract->id]))->assertSessionHasErrors('contract_ids');
});

it('keeps contracts of another company out of the asset form', function () {
    $theirContract = asTenant(createTenant('other'), fn () => createContract(createCustomer(), ['contract_no' => 'MA-THEIRS']));

    $this->actingAs($this->admin)->post('/assets', [
        'category_id' => $this->category->id, 'name' => 'Sneaky', 'status' => 'in_use', 'owner' => 'customer',
        'customer_id' => $this->customer->id, 'location' => 'ชั้น 2', 'contract_ids' => [$theirContract->id],
    ])->assertSessionHasErrors('contract_ids');
    expect(Asset::where('name', 'Sneaky')->exists())->toBeFalse();
});

it('exports the contracts of each asset and imports contract numbers as additions only', function () {
    Storage::fake('local');
    $beta = createContract(createCustomer(['code' => 'BETA']), ['contract_no' => 'MA-BETA']);
    $ended = createContract($this->customer, ['contract_no' => 'MA-OLD', 'starts_on' => now()->subYears(2)->toDateString(), 'ends_on' => now()->subYear()->toDateString()]);
    app(AddContractAssets::class)->handle($ended, [$this->mine->id]);
    app(AddContractAssets::class)->handle($this->contract, [$this->mine->id]);

    $response = $this->actingAs($this->admin)->get('/assets/export?search=Acme+Switch')->assertOk();
    $rows = Excel::toCollection(null, $response->getFile()->getPathname())->first();
    expect($rows[0][22])->toBe('สัญญา MA (เลขที่)')
        ->and($rows[1][22])->toBe('MA-OLD, MA-1');

    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['asset_code', 'name', 'category', 'customer', 'status', 'location', 'contracts'],
        // An empty cell takes it out of nothing.
        [$this->mine->asset_code, 'Acme Switch', $this->category->name, 'ACME', 'in_use', 'ชั้น 1', null],
        [$this->alsoMine->asset_code, 'Acme Router', $this->category->name, 'ACME', 'in_use', 'ชั้น 1', 'ma-1, MA-OLD'],
        [null, 'Acme New AP', $this->category->name, 'ACME', 'in_use', 'ชั้น 2', 'MA-1'],
        [null, 'Wrong customer', $this->category->name, 'ACME', 'in_use', 'ชั้น 2', 'MA-BETA'],
        [null, 'Unknown contract', $this->category->name, 'ACME', 'in_use', 'ชั้น 2', 'MA-NOPE'],
    ], null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'assets').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $this->actingAs($this->admin)->post('/assets/imports', ['file' => new UploadedFile($path, 'assets.xlsx', null, null, true)])->assertSessionHasNoErrors();

    $import = AssetImport::latest('id')->first();
    $newAp = Asset::where('name', 'Acme New AP')->sole();
    expect($import->only(['created_rows', 'updated_rows', 'failed_rows']))->toBe(['created_rows' => 1, 'updated_rows' => 2, 'failed_rows' => 2])
        ->and(collect($import->errors)->pluck('messages')->flatten()->all())->toBe([
            'สัญญา "MA-BETA" เป็นของลูกค้ารายอื่น ทรัพย์สินต้องเป็นของลูกค้าเจ้าของสัญญา',
            'ไม่พบสัญญา "MA-NOPE" หรือคุณไม่มีสิทธิ์แก้ไขสัญญานี้',
        ])
        ->and($this->contract->contractAssets()->pluck('asset_id')->sort()->values()->all())
        ->toBe(collect([$this->mine->id, $this->alsoMine->id, $newAp->id])->sort()->values()->all())
        ->and($ended->contractAssets()->pluck('asset_id')->sort()->values()->all())
        ->toBe(collect([$this->mine->id, $this->alsoMine->id])->sort()->values()->all())
        ->and($beta->contractAssets()->count())->toBe(0)
        ->and(Asset::where('name', 'Wrong customer')->exists())->toBeFalse();
});
