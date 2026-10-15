<?php

use App\Modules\Asset\Models\AssetBrand;
use App\Modules\Asset\Models\AssetModel;

it('adds brands and models to the catalog as assets are saved, matching names without case', function () {
    $category = createAssetCategory();
    $first = createAsset($category, ['brand' => 'Dell', 'model' => 'OptiPlex 7010']);
    $second = createAsset($category, ['brand' => ' DELL ', 'model' => 'optiplex 7010']);
    $third = createAsset($category, ['brand' => 'Dell', 'model' => 'Latitude 5440']);
    $plain = createAsset($category, ['brand' => '', 'model' => 'x']);

    expect(AssetBrand::count())->toBe(1)
        ->and(AssetModel::count())->toBe(2)
        ->and($second->asset_brand_id)->toBe($first->asset_brand_id)
        ->and($second->asset_model_id)->toBe($first->asset_model_id)
        ->and($third->asset_model_id)->not->toBe($first->asset_model_id)
        ->and(AssetModel::find($first->asset_model_id)->asset_category_id)->toBe($category->id)
        ->and($plain->asset_brand_id)->toBeNull()
        ->and($plain->asset_model_id)->toBeNull();
});

it('keeps the catalog ids in step when the brand or model text changes', function () {
    $admin = userWithRole('admin_company');
    $category = createAssetCategory();
    $asset = createAsset($category, ['brand' => 'Dell', 'model' => 'OptiPlex 7010', 'location' => 'ชั้น 2']);

    $this->actingAs($admin)->put("/assets/{$asset->ulid}", assetForm($asset, ['brand' => 'HP', 'model' => 'ProDesk 400']))
        ->assertSessionHasNoErrors();

    $asset->refresh();
    expect(AssetBrand::find($asset->asset_brand_id)->name)->toBe('HP')
        ->and(AssetModel::find($asset->asset_model_id)->name)->toBe('ProDesk 400');

    $this->actingAs($admin)->put("/assets/{$asset->ulid}", assetForm($asset, ['brand' => null, 'model' => null]))
        ->assertSessionHasNoErrors();
    expect($asset->refresh()->asset_brand_id)->toBeNull()->and($asset->asset_model_id)->toBeNull();
});

it('keeps the catalog per tenant', function () {
    createAsset(createAssetCategory(), ['brand' => 'Dell', 'model' => 'OptiPlex']);

    $other = createTenant('other');
    asTenant($other, fn () => createAsset(createAssetCategory(), ['brand' => 'Dell', 'model' => 'OptiPlex']));

    expect(AssetBrand::count())->toBe(1)->and(AssetModel::count())->toBe(1)
        ->and(asTenant($other, fn () => AssetBrand::count()))->toBe(1);
});

it('puts a part under a parent asset of the same company, one level deep', function () {
    $admin = userWithRole('admin_company');
    $category = createAssetCategory();
    $pc = createAsset($category, ['location' => 'ชั้น 2']);
    $monitor = createAsset($category, ['location' => 'ชั้น 2']);

    $this->actingAs($admin)->put("/assets/{$monitor->ulid}", assetForm($monitor, ['parent_asset_id' => $pc->id, 'floor' => '2', 'room' => 'บัญชี']))
        ->assertSessionHasNoErrors();
    $monitor->refresh();
    expect($monitor->parent_asset_id)->toBe($pc->id)
        ->and($monitor->floor)->toBe('2')->and($monitor->room)->toBe('บัญชี')
        ->and($pc->children()->pluck('id')->all())->toBe([$monitor->id]);

    // not itself, not under a part
    $this->actingAs($admin)->put("/assets/{$pc->ulid}", assetForm($pc, ['parent_asset_id' => $pc->id]))->assertSessionHasErrors('parent_asset_id');
    $this->actingAs($admin)->put("/assets/{$pc->ulid}", assetForm($pc, ['parent_asset_id' => $monitor->id]))->assertSessionHasErrors('parent_asset_id');

    // not an asset of another company
    $theirs = asTenant(createTenant('other'), fn () => createAsset(createAssetCategory(), ['location' => 'x']));
    $this->actingAs($admin)->put("/assets/{$monitor->ulid}", assetForm($monitor, ['parent_asset_id' => $theirs->id]))->assertSessionHasErrors('parent_asset_id');
});

/**
 * The asset form as the edit page sends it, with changes.
 */
function assetForm($asset, array $changes = []): array
{
    return $changes + [
        'category_id' => $asset->category_id,
        'name' => $asset->name,
        'brand' => $asset->brand,
        'model' => $asset->model,
        'location' => $asset->location ?? 'x',
        'owner' => 'company',
        'quantity' => 1,
        'status' => $asset->status,
        'serials' => [],
    ];
}
