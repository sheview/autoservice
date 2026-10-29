<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Platform\Support\SimilarText;

/*
 * Parts and assets that may be the one being typed, though not the same to the letter
 * (FindSimilarParts / FindSimilarAssets): shown on the forms as a hint, never refusing the save.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('scores words in another order, a code held in another and a typo as alike', function () {
    expect(SimilarText::score('Toner HP 59A', 'HP 59A Toner'))->toBeGreaterThanOrEqual(SimilarText::THRESHOLD)
        ->and(SimilarText::score('CF259A', 'CF259AX'))->toBeGreaterThanOrEqual(SimilarText::THRESHOLD)
        ->and(SimilarText::score('Power supply 650W', 'Power suppy 650W'))->toBeGreaterThanOrEqual(SimilarText::THRESHOLD)
        ->and(SimilarText::score('Toner HP 59A', 'RAM 8GB DDR4'))->toBeLessThan(SimilarText::THRESHOLD)
        ->and(SimilarText::score('', 'RAM'))->toBe(0.0);
});

it('hints at parts that may be the one typed, after the same ones', function () {
    $toner = createPart(['code' => 'PT-1', 'name' => 'Toner HP 59A', 'part_number' => 'CF259A']);
    $ram = createPart(['code' => 'PT-2', 'name' => 'RAM 8GB DDR4', 'part_number' => 'KVR26N19']);

    $this->actingAs($this->admin)->getJson('/parts/duplicates?name='.urlencode('HP 59A Toner cartridge'))
        ->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.code', 'PT-1')->assertJsonPath('0.reasons', ['similar_name'])->assertJsonPath('0.blocking', false);
    $this->actingAs($this->admin)->getJson('/parts/duplicates?name=Something&part_number=CF259AX')
        ->assertJsonPath('0.reasons', ['similar_part_number']);
    // The same one first (blocking), not shown again as alike.
    $this->actingAs($this->admin)->getJson('/parts/duplicates?name='.urlencode('toner hp-59a'))
        ->assertJsonCount(1)->assertJsonPath('0.blocking', true)->assertJsonPath('0.reasons', ['name']);
    // A hint does not stop the save.
    $this->actingAs($this->admin)->post('/parts', ['name' => 'HP 59A Toner cartridge', 'unit' => 'ตลับ'])->assertSessionHasNoErrors();

    expect($ram->name)->toBe('RAM 8GB DDR4');
});

it('hints at assets with a serial held in the one typed, or a name alike of the same owner', function () {
    $category = createAssetCategory(['name' => 'Switch']);
    $switch = createAsset($category, ['asset_code' => 'SW-1', 'name' => 'สวิตช์ Cisco', 'brand' => 'Cisco', 'model' => 'C9200-24T', 'serial_number' => 'SW00026']);
    createAsset($category, ['asset_code' => 'SW-2', 'name' => 'สวิตช์ Cisco', 'brand' => 'Cisco', 'model' => 'C9200-24T', 'serial_number' => 'SW00027']);

    $this->actingAs($this->admin)->getJson('/assets/duplicates?'.http_build_query(['serials' => ['SMPSW00026']]))
        ->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.asset_code', 'SW-1')->assertJsonPath('0.reasons', ['similar_serial'])->assertJsonPath('0.blocking', false);

    // Alike by name: only of the same owner (units of one model elsewhere are normal).
    $this->actingAs($this->admin)->getJson('/assets/duplicates?'.http_build_query(['name' => 'Cisco สวิตช์', 'brand' => 'Cisco', 'model' => 'C9200 24T']))
        ->assertJsonCount(2)->assertJsonPath('0.reasons', ['similar_name']);
    $this->actingAs($this->admin)->getJson('/assets/duplicates?'.http_build_query(['name' => 'Cisco สวิตช์', 'brand' => 'Cisco', 'model' => 'C9200 24T', 'customer_id' => createCustomer()->id]))
        ->assertJsonCount(0);

    expect(Asset::count())->toBe(2);
});

it('never hints at a part or asset of another company', function () {
    asTenant(createTenant('other'), function () {
        createPart(['code' => 'PT-9', 'name' => 'Toner HP 59A']);
        createAsset(createAssetCategory(), ['serial_number' => 'SW00026']);
    });

    $this->actingAs($this->admin)->getJson('/parts/duplicates?name='.urlencode('HP 59A Toner'))->assertJsonCount(0);
    $this->actingAs($this->admin)->getJson('/assets/duplicates?'.http_build_query(['serials' => ['SMPSW00026']]))->assertJsonCount(0);
});
