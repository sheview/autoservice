<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\StorageLocation;
use App\Modules\Inventory\Models\Part;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('adds, renames, switches off and lists storage locations with search, filter and counts', function () {
    $this->actingAs($this->admin)->post('/storage-locations', ['name' => ' คลังหลัก ', 'notes' => 'ชั้น 1'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/storage-locations', ['name' => 'คลังหลัก'])->assertSessionHasErrors('name');
    $this->actingAs($this->admin)->post('/storage-locations', ['name' => 'ห้อง IT'])->assertSessionHasNoErrors();
    $main = StorageLocation::where('name', 'คลังหลัก')->sole();
    $it = StorageLocation::where('name', 'ห้อง IT')->sole();

    $this->actingAs($this->admin)->put("/storage-locations/{$it->id}", ['name' => 'ห้อง IT ชั้น 2', 'is_active' => false])->assertSessionHasNoErrors();
    expect($it->fresh()->only(['name', 'is_active']))->toBe(['name' => 'ห้อง IT ชั้น 2', 'is_active' => false]);

    createAsset(createAssetCategory(), ['storage_location_id' => $main->id]);
    createPart(['code' => 'P1', 'storage_location_id' => $main->id]);

    $this->actingAs($this->admin)->get('/storage-locations')->assertInertia(fn (Assert $page) => $page
        ->component('Asset/StorageLocations/Index')
        ->where('locations.total', 2)
        ->where('locations.data.0.name', 'คลังหลัก')->where('locations.data.0.assets_count', 1)->where('locations.data.0.parts_count', 1));
    $this->actingAs($this->admin)->get('/storage-locations?status=inactive')->assertInertia(fn (Assert $page) => $page
        ->where('locations.total', 1)->where('locations.data.0.name', 'ห้อง IT ชั้น 2'));
    $this->actingAs($this->admin)->get('/storage-locations?search=ชั้น 1')->assertInertia(fn (Assert $page) => $page
        ->where('locations.total', 1)->where('locations.data.0.name', 'คลังหลัก'));
});

it('offers only active locations on the asset and part forms, keeping the one already picked', function () {
    $main = StorageLocation::create(['name' => 'คลังหลัก']);
    $old = StorageLocation::create(['name' => 'คลังเก่า', 'is_active' => false]);
    $part = createPart(['code' => 'P1', 'storage_location_id' => $old->id]);

    $this->actingAs($this->admin)->get('/parts/create')->assertInertia(fn (Assert $page) => $page
        ->where('locations', [['id' => $main->id, 'name' => 'คลังหลัก']]));
    $this->actingAs($this->admin)->get("/parts/{$part->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('locations', fn ($locations) => collect($locations)->pluck('id')->sort()->values()->all() === [$main->id, $old->id]));
    $this->actingAs($this->admin)->get('/assets/create')->assertInertia(fn (Assert $page) => $page
        ->where('locations', [['id' => $main->id, 'name' => 'คลังหลัก']]));
});

it('keeps parts and assets at a location, filters the lists by it and shows it', function () {
    $main = StorageLocation::create(['name' => 'คลังหลัก']);
    $category = createAssetCategory(['name' => 'Toner', 'code_prefix' => 'TN']);

    $this->actingAs($this->admin)->post('/parts', ['code' => 'TN-1', 'name' => 'Toner 59A', 'unit' => 'ตลับ', 'storage_location_id' => $main->id])
        ->assertSessionHasNoErrors();
    createPart(['code' => 'TN-2']);
    $part = Part::where('code', 'TN-1')->sole();
    expect($part->storage_location_id)->toBe($main->id);

    $this->actingAs($this->admin)->get("/parts?storage_location_id={$main->id}")->assertInertia(fn (Assert $page) => $page
        ->where('parts.total', 1)->where('parts.data.0.code', 'TN-1'));
    $this->actingAs($this->admin)->get("/parts/{$part->id}")->assertInertia(fn (Assert $page) => $page->where('part.storage_location', 'คลังหลัก'));

    $asset = createAsset($category, ['storage_location_id' => $main->id]);
    createAsset($category);
    $this->actingAs($this->admin)->get("/assets?storage_location_id={$main->id}")->assertInertia(fn (Assert $page) => $page
        ->where('assets.total', 1)->where('assets.data.0.ulid', $asset->ulid));
    $this->actingAs($this->admin)->get("/assets/{$asset->ulid}")->assertInertia(fn (Assert $page) => $page->where('asset.storage_location', 'คลังหลัก'));
});

it('leaves what was kept at a removed location with none', function () {
    $main = StorageLocation::create(['name' => 'คลังหลัก']);
    $asset = createAsset(createAssetCategory(), ['storage_location_id' => $main->id]);
    $part = createPart(['code' => 'P1', 'storage_location_id' => $main->id]);

    $this->actingAs($this->admin)->delete("/storage-locations/{$main->id}")->assertSessionHasNoErrors();

    expect(StorageLocation::count())->toBe(0)
        ->and(Asset::find($asset->id)->storage_location_id)->toBeNull()
        ->and($part->fresh()->storage_location_id)->toBeNull();
});

it('lets helpdesk view the locations but not change them', function () {
    $helpdesk = userWithRole('helpdesk');
    $main = StorageLocation::create(['name' => 'คลังหลัก']);

    $this->actingAs($helpdesk)->get('/storage-locations')->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    $this->actingAs($helpdesk)->post('/storage-locations', ['name' => 'X'])->assertForbidden();
    $this->actingAs($helpdesk)->put("/storage-locations/{$main->id}", ['name' => 'X'])->assertForbidden();
    $this->actingAs($helpdesk)->delete("/storage-locations/{$main->id}")->assertForbidden();
});

it('keeps each company to its own locations', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, fn () => StorageLocation::create(['name' => 'คลังของเขา']));
    StorageLocation::create(['name' => 'คลังของเรา']);

    $this->actingAs($this->admin)->get('/storage-locations')->assertInertia(fn (Assert $page) => $page
        ->where('locations.total', 1)->where('locations.data.0.name', 'คลังของเรา'));
    $this->actingAs($this->admin)->put("/storage-locations/{$theirs->id}", ['name' => 'X'])->assertNotFound();
    $this->actingAs($this->admin)->delete("/storage-locations/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->admin)->post('/parts', ['code' => 'P9', 'name' => 'X', 'unit' => 'pcs', 'storage_location_id' => $theirs->id])
        ->assertSessionHasErrors('storage_location_id');
    // Same name in another company is fine.
    $this->actingAs($this->admin)->post('/storage-locations', ['name' => 'คลังของเขา'])->assertSessionHasNoErrors();

    asTenant($other, fn () => expect(StorageLocation::sole()->name)->toBe('คลังของเขา'));
});
