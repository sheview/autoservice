<?php

use App\Modules\Asset\Models\Asset;

/*
 * The same device typed twice (FindDuplicateAssets): equipment number, IP or MAC refuse the save
 * naming the asset; the same name, brand, model and owner on a new asset asks to confirm.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->switches = createAssetCategory(['name' => 'Switch', 'code_prefix' => 'SW']);
    $this->core = createAsset($this->switches, [
        'asset_code' => 'SW-00001', 'name' => 'Core switch', 'brand' => 'Cisco', 'model' => 'C9200',
        'property_no' => 'ครุภัณฑ์ 01/2569', 'ip_address' => '10.0.0.1', 'mac_address' => 'AA:BB:CC:DD:EE:01',
    ]);
    $this->create = fn (array $data) => $this->actingAs($this->admin)->post('/assets', $data + [
        'category_id' => $this->switches->id, 'name' => 'Access switch', 'status' => 'in_use', 'owner' => 'company', 'location' => 'Rack 1',
    ]);
});

it('refuses an equipment number, IP or MAC another asset has, naming it', function () {
    ($this->create)(['property_no' => 'ครุภัณฑ์01-2569'])
        ->assertSessionHasErrors(['property_no' => 'เลขครุภัณฑ์ซ้ำกับทรัพย์สิน SW-00001 Core switch']);
    ($this->create)(['ip_address' => '10.0.0.1'])
        ->assertSessionHasErrors(['ip_address' => 'IP Address ซ้ำกับทรัพย์สิน SW-00001 Core switch']);
    ($this->create)(['mac_address' => 'aa-bb-cc-dd-ee-01'])->assertSessionHasErrors('mac_address');
    // Confirming does not pass the same device.
    ($this->create)(['ip_address' => '10.0.0.1', 'confirm_duplicate' => true])->assertSessionHasErrors('ip_address');

    expect(Asset::count())->toBe(1);
});

it('asks to confirm a new asset of the same name, brand, model and owner, then saves it', function () {
    $same = ['name' => 'core SWITCH', 'brand' => 'cisco', 'model' => 'C-9200'];

    ($this->create)($same)->assertSessionHasErrors(['duplicate']);
    expect(Asset::count())->toBe(1);

    ($this->create)($same + ['confirm_duplicate' => true])->assertSessionHasNoErrors();
    // Another owner is another device.
    ($this->create)($same + ['owner' => 'customer', 'customer_id' => createCustomer()->id])->assertSessionHasNoErrors();
    expect(Asset::count())->toBe(3);
});

it('lets an asset keep its own numbers when edited, without asking about its model', function () {
    createAsset($this->switches, ['name' => 'Core switch', 'brand' => 'Cisco', 'model' => 'C9200']);

    $this->actingAs($this->admin)->put("/assets/{$this->core->ulid}", [
        'category_id' => $this->switches->id, 'asset_code' => 'SW-00001', 'name' => 'Core switch', 'brand' => 'Cisco', 'model' => 'C9200',
        'status' => 'in_use', 'owner' => 'company', 'location' => 'Rack 1',
        'property_no' => 'ครุภัณฑ์ 01/2569', 'ip_address' => '10.0.0.1', 'mac_address' => 'AA:BB:CC:DD:EE:01',
    ])->assertSessionHasNoErrors();
});

it('shows the form the assets it would repeat, blocking or not', function () {
    $this->actingAs($this->admin)->getJson('/assets/duplicates?ip_address=10.0.0.1')
        ->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.asset_code', 'SW-00001')->assertJsonPath('0.reasons', ['ip_address'])->assertJsonPath('0.blocking', true)
        ->assertJsonPath('0.url', route('asset.assets.show', $this->core->ulid));
    $this->actingAs($this->admin)->getJson('/assets/duplicates?name=Core%20switch&brand=Cisco&model=C9200')
        ->assertJsonPath('0.reasons', ['same_model'])->assertJsonPath('0.blocking', false)
        ->assertJsonPath('0.copy_url', route('asset.assets.create', ['from' => $this->core->ulid]));
    // Editing: only the blocking checks, never the asset itself.
    $this->actingAs($this->admin)->getJson("/assets/duplicates?name=Core%20switch&brand=Cisco&model=C9200&ip_address=10.0.0.1&except={$this->core->ulid}")
        ->assertJsonCount(0);
});

it('does not count an asset of another company', function () {
    asTenant(createTenant('other'), fn () => createAsset(createAssetCategory(), ['ip_address' => '10.9.9.9', 'property_no' => 'P-9']));

    ($this->create)(['ip_address' => '10.9.9.9', 'property_no' => 'P-9'])->assertSessionHasNoErrors();
});
