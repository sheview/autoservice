<?php

use App\Modules\Inventory\Models\Part;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('offers the asset categories on the part form and shows the one picked', function () {
    $category = createAssetCategory(['name' => 'Power Supply', 'code_prefix' => 'PSU']);

    $this->actingAs($this->admin)->get('/parts/create')->assertInertia(fn (Assert $page) => $page
        ->where('categories', [['id' => $category->id, 'name' => 'Power Supply', 'requires_serial' => false]]));

    $this->actingAs($this->admin)->post('/parts', ['code' => 'PSU-1', 'name' => 'PSU 550W', 'unit' => 'pcs', 'asset_category_id' => $category->id])
        ->assertSessionHasNoErrors();
    $part = Part::where('code', 'PSU-1')->sole();

    expect($part->asset_category_id)->toBe($category->id);
    $this->actingAs($this->admin)->get("/parts/{$part->id}")->assertInertia(fn (Assert $page) => $page->where('part.category', 'Power Supply'));
});

it('leaves the parts of a removed category with none', function () {
    $category = createAssetCategory(['name' => 'Toner', 'code_prefix' => 'TN']);
    $part = createPart(['code' => 'TN-1', 'asset_category_id' => $category->id]);

    $this->actingAs($this->admin)->delete("/asset-categories/{$category->id}")->assertSessionHasNoErrors();

    expect($part->fresh()->asset_category_id)->toBeNull();
});

it('does not let a part use a category of another company', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, fn () => createAssetCategory(['name' => 'RAM', 'code_prefix' => 'RAM']));

    $this->actingAs($this->admin)->post('/parts', ['code' => 'RAM-1', 'name' => 'RAM 8GB', 'unit' => 'pcs', 'asset_category_id' => $theirs->id])
        ->assertSessionHasErrors('asset_category_id');
    $this->actingAs($this->admin)->get('/parts/create')->assertInertia(fn (Assert $page) => $page->where('categories', []));
    expect(Part::count())->toBe(0);
});
