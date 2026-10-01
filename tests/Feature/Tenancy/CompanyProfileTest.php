<?php

use App\Modules\Contract\Actions\AddContractAssets;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\CompanyProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = userWithRole('admin_company');
});

it('lets the company admin set the logo, service phone and e-mail', function () {
    $this->actingAs($this->admin)->get('/company')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Tenancy/Company')
            ->where('company.name', $this->tenant->name)
            ->where('company.logo_url', null));

    $this->actingAs($this->admin)->post('/company', [
        '_method' => 'put', 'service_phone' => ' 02-1014461 ', 'service_email' => 'service@itbtthai.com',
        'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
    ])->assertSessionHasNoErrors();

    $tenant = $this->tenant->fresh();
    $profile = CompanyProfile::of($tenant);
    expect($profile['service_phone'])->toBe('02-1014461')
        ->and($profile['service_email'])->toBe('service@itbtthai.com')
        ->and($profile['logo_url'])->toStartWith(route('tenancy.company.logo'))
        ->and($tenant->getFirstMedia(CompanyProfile::LOGO)->tenant_id)->toBe($tenant->id);

    // every user of the company sees the logo (it is printed on labels)
    $this->actingAs(userWithRole('technician'))->get('/company/logo')->assertOk()->assertHeader('content-type', 'image/png');

    // a new logo replaces the old one; removing leaves none
    $this->actingAs($this->admin)->post('/company', ['_method' => 'put', 'logo' => UploadedFile::fake()->image('new.jpg')]);
    expect($tenant->fresh()->getMedia(CompanyProfile::LOGO))->toHaveCount(1);
    $this->actingAs($this->admin)->put('/company', ['remove_logo' => true, 'service_phone' => '02-1014461']);
    expect($tenant->fresh()->getMedia(CompanyProfile::LOGO))->toHaveCount(0);
    $this->actingAs($this->admin)->get('/company/logo')->assertNotFound();
});

it('validates the profile', function () {
    $this->actingAs($this->admin)->put('/company', [
        'service_email' => 'not-an-email',
        'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors(['service_email', 'logo']);
});

it('lets only the company admin change it, and keeps each company to its own', function () {
    $this->actingAs(userWithRole('technician'))->get('/company')->assertForbidden();
    $this->actingAs(userWithRole('helpdesk'))->put('/company', ['service_phone' => '1'])->assertForbidden();
    $this->actingAs($this->admin)->get('/company')->assertInertia(fn (Assert $page) => $page->where('can.manage', true));

    // company.view opens the page read only; company.manage is needed to change it
    grantTo('helpdesk', ['company.view']);
    $helpdesk = userWithRole('helpdesk');
    $this->actingAs($helpdesk)->get('/company')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    $this->actingAs($helpdesk)->put('/company', ['service_phone' => '1'])->assertForbidden();

    $this->actingAs($this->admin)->put('/company', ['service_phone' => '02-1111111']);

    $other = createTenant('other');
    $otherAdmin = userWithRole('admin_company', [], $other);
    $this->actingAs($otherAdmin)->get('/company')->assertInertia(fn (Assert $page) => $page->where('company.service_phone', null));

    // the platform has no company profile of its own, and its menu does not offer one
    $superadmin = createSuperadmin();
    $this->actingAs($superadmin)->get('/company')->assertNotFound();
    $this->actingAs($superadmin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('navigation', fn ($items) => ! collect($items)->pluck('title')->contains('ข้อมูลบริษัท')));

    // working inside a company, the superadmin finds it in the menu and can set it
    $this->actingAs($superadmin)->post("/platform/impersonation/{$this->tenant->ulid}");
    $this->actingAs($superadmin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('navigation', fn ($items) => collect($items)->pluck('title')->contains('ข้อมูลบริษัท')));
    $this->actingAs($superadmin)->get('/company')->assertOk();
});

it('prints the short name of the customer on labels when it has one', function () {
    $short = createCustomer(['name' => 'สำนักงานปลัดกระทรวงพลังงาน', 'short_name' => 'สป.พลังงาน']);
    $full = createCustomer(['name' => 'Acme']);
    $a = createAsset(createAssetCategory(), ['customer_id' => $short->id]);
    $b = createAsset(createAssetCategory(), ['customer_id' => $full->id]);

    $this->actingAs($this->admin)->get("/labels/print?assets={$a->ulid},{$b->ulid}&template=a3_5x6")
        ->assertInertia(fn (Assert $page) => $page
            ->where('labels.0.customer', 'สป.พลังงาน')
            ->where('labels.1.customer', 'Acme'));

    // set from the customer form
    $this->actingAs($this->admin)->put("/customers/{$full->id}", ['code' => $full->code, 'name' => 'Acme', 'short_name' => 'ACM'])
        ->assertSessionHasNoErrors();
    expect($full->fresh()->short_name)->toBe('ACM');
});

it('prints the company header, the contract and the province on detailed labels', function () {
    $this->actingAs($this->admin)->put('/company', ['service_phone' => '02-1014461', 'service_email' => 'service@itbtthai.com']);

    $branch = Branch::create(['code' => 'BKK', 'name' => 'Head office', 'province' => 'กรุงเทพมหานคร']);
    $customer = createCustomer(['name' => 'สป.พลังงาน']);
    $asset = createAsset(createAssetCategory(), [
        'name' => 'เครื่องสำรองไฟฟ้า ขนาด 1 KVA', 'brand' => 'Chuphotic', 'model' => 'MW1050IIA', 'serial_number' => '16831SS171598',
        'customer_id' => $customer->id, 'branch_id' => $branch->id,
    ]);
    $contract = createContract($customer, ['contract_no' => '6/68', 'title' => 'สัญญาจ้างบำรุงรักษา', 'starts_on' => '2024-10-09', 'ends_on' => now()->addMonths(3)->toDateString()]);
    app(AddContractAssets::class)->handle($contract, [$asset->id]);

    $this->actingAs($this->admin)->get("/labels/print?assets={$asset->ulid}&template=a3_5x6")
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.service_phone', '02-1014461')
            ->where('labels.0.brand', 'Chuphotic')
            ->where('labels.0.model', 'MW1050IIA')
            ->where('labels.0.customer', 'สป.พลังงาน')
            ->where('labels.0.province', 'กรุงเทพมหานคร')
            ->where('labels.0.contract.contract_no', '6/68')
            ->where('labels.0.contract.period', fn (string $period) => str_starts_with($period, 'ระยะเวลาสัญญา : 9 ตุลาคม 2567 – '))
            ->where('templates', fn ($templates) => collect($templates)->firstWhere('key', 'a3_5x6')['paper']['size'] === 'A3 landscape'));

    // small labels do not look the contract up
    $this->actingAs($this->admin)->get("/labels/print?assets={$asset->ulid}&template=roll_50x30")
        ->assertInertia(fn (Assert $page) => $page->where('labels.0.contract', null));
});
