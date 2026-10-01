<?php

use App\Modules\Asset\Models\AssetCategory;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('creates a category with spec fields', function () {
    $this->actingAs($this->admin)->post('/asset-categories', [
        'name' => 'Switch',
        'code_prefix' => 'sw',
        'service_line' => 'network',
        'spec_fields' => [
            ['key' => 'ports', 'label' => ' จำนวนพอร์ต ', 'type' => 'number', 'required' => true],
            ['key' => 'layer', 'label' => 'Layer', 'type' => 'select', 'options' => ['L2', ' L3 ', '', 'L2']],
        ],
    ])->assertRedirect('/asset-categories')->assertSessionHasNoErrors();

    $category = AssetCategory::first();
    expect($category->code_prefix)->toBe('SW')
        ->and($category->spec_fields)->toEqual([
            ['key' => 'ports', 'label' => 'จำนวนพอร์ต', 'type' => 'number', 'options' => [], 'required' => true],
            ['key' => 'layer', 'label' => 'Layer', 'type' => 'select', 'options' => ['L2', 'L3'], 'required' => false],
        ]);
});

it('rejects bad spec fields', function () {
    $this->actingAs($this->admin)->post('/asset-categories', [
        'name' => 'Bad',
        'code_prefix' => 'B-1',
        'spec_fields' => [
            ['key' => 'Bad Key', 'label' => 'x', 'type' => 'text'],
            ['key' => 'dup', 'label' => 'x', 'type' => 'color'],
            ['key' => 'dup', 'label' => 'x', 'type' => 'select'],
        ],
    ])->assertSessionHasErrors([
        'code_prefix', 'spec_fields.0.key', 'spec_fields.1.type', 'spec_fields.1.key', 'spec_fields.2.options',
    ]);
});

it('rejects a duplicate name ignoring case', function () {
    createAssetCategory(['name' => 'Printer']);

    $this->actingAs($this->admin)->post('/asset-categories', ['name' => 'PRINTER', 'code_prefix' => 'PR'])
        ->assertSessionHasErrors('name');
});

it('lists categories with search, filter and asset counts', function () {
    $pc = createAssetCategory(['name' => 'Computer', 'code_prefix' => 'PC', 'service_line' => 'pc']);
    createAssetCategory(['name' => 'Router', 'code_prefix' => 'RT', 'service_line' => 'network']);
    createAsset($pc);

    $this->actingAs($this->admin)->get('/asset-categories?service_line=pc')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Asset/Categories/Index')
            ->where('categories.total', 1)
            ->where('categories.data.0.assets_count', 1));

    $this->actingAs($this->admin)->get('/asset-categories?search=RT')
        ->assertInertia(fn (Assert $page) => $page->where('categories.total', 1)->where('categories.data.0.name', 'Router'));
});

it('keeps hardware or software on the category', function () {
    $this->actingAs($this->admin)->post('/asset-categories', ['name' => 'Licence', 'code_prefix' => 'LIC', 'asset_type' => 'software'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/asset-categories', ['name' => 'Desktop', 'code_prefix' => 'PC'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/asset-categories', ['name' => 'Bad', 'code_prefix' => 'BD', 'asset_type' => 'firmware'])
        ->assertSessionHasErrors('asset_type');

    $licence = AssetCategory::where('name', 'Licence')->sole();
    expect($licence->asset_type)->toBe('software')
        ->and(AssetCategory::where('name', 'Desktop')->sole()->asset_type)->toBe('hardware');

    // an edit that leaves the type out keeps it
    $this->actingAs($this->admin)->put("/asset-categories/{$licence->id}", ['name' => 'Licences', 'code_prefix' => 'LIC'])
        ->assertSessionHasNoErrors();
    expect($licence->fresh()->asset_type)->toBe('software');

    $this->actingAs($this->admin)->get('/asset-categories?asset_type=software')
        ->assertInertia(fn (Assert $page) => $page->where('categories.total', 1)->where('categories.data.0.asset_type', 'software'));
});

it('deletes only a category without assets', function () {
    $used = createAssetCategory(['name' => 'Used']);
    $empty = createAssetCategory(['name' => 'Empty']);
    createAsset($used);

    $this->actingAs($this->admin)->delete("/asset-categories/{$used->id}")->assertSessionHasErrors('category');
    $this->actingAs($this->admin)->delete("/asset-categories/{$empty->id}")->assertSessionHasNoErrors();

    expect(AssetCategory::pluck('name')->all())->toBe(['Used']);
});

it('lets helpdesk view but not change categories', function () {
    $helpdesk = userWithRole('helpdesk');
    $category = createAssetCategory();

    $this->actingAs($helpdesk)->get('/asset-categories')->assertOk();
    $this->actingAs($helpdesk)->post('/asset-categories', ['name' => 'X', 'code_prefix' => 'X'])->assertForbidden();
    $this->actingAs($helpdesk)->put("/asset-categories/{$category->id}", ['name' => 'X', 'code_prefix' => 'X'])->assertForbidden();

    $this->actingAs(userWithRole('technician'))->get('/asset-categories')->assertForbidden();
});
