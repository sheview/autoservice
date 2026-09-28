<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Platform\Models\Activity;
use App\Modules\Tenancy\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company', ['name' => 'Admin Here']);
    $this->pc = createAssetCategory([
        'name' => 'คอมพิวเตอร์',
        'code_prefix' => 'PC',
        'spec_fields' => [
            ['key' => 'cpu', 'label' => 'CPU', 'type' => 'text', 'options' => [], 'required' => true],
            ['key' => 'ram_gb', 'label' => 'RAM (GB)', 'type' => 'number', 'options' => [], 'required' => false],
            ['key' => 'os', 'label' => 'OS', 'type' => 'select', 'options' => ['Windows 11', 'Ubuntu'], 'required' => false],
        ],
    ]);
});

function assetPayload(array $overrides = []): array
{
    return $overrides + [
        'category_id' => test()->pc->id,
        'name' => 'PC ฝ่ายบัญชี',
        'status' => 'in_use',
        'specs' => ['cpu' => 'Core i5'],
    ];
}

it('creates an asset with the next code of the category prefix', function () {
    $this->actingAs($this->admin)->post('/assets', assetPayload())->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/assets', assetPayload(['name' => 'Second']))->assertSessionHasNoErrors();

    expect(Asset::orderBy('id')->pluck('asset_code')->all())->toBe(['PC-00001', 'PC-00002']);

    $asset = Asset::first();
    expect($asset->ulid)->toHaveLength(26)
        ->and($asset->tenant_id)->toBe($this->tenant->id);
});

it('redirects to the asset page by ulid', function () {
    $response = $this->actingAs($this->admin)->post('/assets', assetPayload());

    $response->assertRedirect(route('asset.assets.show', Asset::first()->ulid));
});

it('keeps a hand-typed code and skips it when generating', function () {
    $this->actingAs($this->admin)->post('/assets', assetPayload(['asset_code' => 'PC-00001']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/assets', assetPayload())->assertSessionHasNoErrors();

    expect(Asset::orderBy('id')->pluck('asset_code')->all())->toBe(['PC-00001', 'PC-00002']);
});

it('rejects a code already used in the tenant, even by a deleted asset', function () {
    $old = createAsset($this->pc, ['asset_code' => 'OLD-1', 'specs' => ['cpu' => 'x']]);
    $old->delete();

    $this->actingAs($this->admin)->post('/assets', assetPayload(['asset_code' => 'OLD-1']))
        ->assertSessionHasErrors('asset_code');
});

it('validates specs against the category fields and drops unknown ones', function () {
    $this->actingAs($this->admin)->post('/assets', assetPayload(['specs' => ['ram_gb' => 'lots', 'os' => 'DOS']]))
        ->assertSessionHasErrors(['specs.cpu', 'specs.ram_gb', 'specs.os']);

    expect(session('errors')->first('specs.cpu'))->toBe('กรุณากรอก CPU');

    $this->actingAs($this->admin)->post('/assets', assetPayload([
        'specs' => ['cpu' => 'Core i7', 'ram_gb' => '16', 'os' => 'Ubuntu', 'hacker' => 'x'],
    ]))->assertSessionHasNoErrors();

    // toEqual: JSONB does not keep key order.
    expect(Asset::first()->specs)->toEqual(['cpu' => 'Core i7', 'ram_gb' => 16, 'os' => 'Ubuntu'])
        ->and(Asset::first()->specs['ram_gb'])->toBe(16);
});

it('stores the purchase price as integer satang', function () {
    $this->actingAs($this->admin)->post('/assets', assetPayload(['purchase_price' => '12500.50']))->assertSessionHasNoErrors();

    expect(Asset::first()->purchase_price)->toBe(1250050);

    $this->actingAs($this->admin)->post('/assets', assetPayload(['purchase_price' => '1.234']))
        ->assertSessionHasErrors('purchase_price');
});

it('searches, filters and sorts on the server', function () {
    $net = createAssetCategory(['name' => 'Switch', 'code_prefix' => 'SW']);
    createAsset($this->pc, ['name' => 'PC A', 'serial_number' => 'SN-777', 'specs' => ['cpu' => 'x']]);
    createAsset($this->pc, ['name' => 'PC B', 'status' => Asset::STATUS_IN_REPAIR, 'specs' => ['cpu' => 'x']]);
    createAsset($net, ['name' => 'Core Switch', 'warranty_expires_at' => now()->subDay()->toDateString()]);

    $this->actingAs($this->admin)->get('/assets?search=SN-777')
        ->assertInertia(fn (Assert $page) => $page->component('Asset/Assets/Index')->where('assets.total', 1)->where('assets.data.0.name', 'PC A'));

    $this->actingAs($this->admin)->get("/assets?category_id={$net->id}")
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1)->where('assets.data.0.asset_code', 'SW-00001'));

    $this->actingAs($this->admin)->get('/assets?status=in_repair')
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1)->where('assets.data.0.name', 'PC B'));

    $this->actingAs($this->admin)->get('/assets?warranty=expired')
        ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1)->where('assets.data.0.name', 'Core Switch'));

    $this->actingAs($this->admin)->get('/assets?sort=asset_code&direction=desc')
        ->assertInertia(fn (Assert $page) => $page->where('assets.data.0.asset_code', 'SW-00001')->where('assets.data.2.asset_code', 'PC-00001'));
});

it('shows, updates and soft-deletes an asset, logging who did it', function () {
    $asset = createAsset($this->pc, ['name' => 'Old name', 'specs' => ['cpu' => 'x']]);

    $this->actingAs($this->admin)->get("/assets/{$asset->ulid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Asset/Assets/Show')
            ->where('asset.asset_code', 'PC-00001')
            ->where('asset.specs.0', ['label' => 'CPU', 'value' => 'x']));

    $this->actingAs($this->admin)->put("/assets/{$asset->ulid}", assetPayload(['name' => 'New name', 'asset_code' => '']))
        ->assertSessionHasNoErrors();
    expect($asset->fresh()->name)->toBe('New name')
        ->and($asset->fresh()->asset_code)->toBe('PC-00001');

    $log = Activity::where('subject_type', $asset->getMorphClass())->where('subject_id', $asset->id)->where('event', 'updated')->first();
    expect($log->properties['actor']['name'])->toBe('Admin Here');

    $this->actingAs($this->admin)->delete("/assets/{$asset->ulid}")->assertRedirect('/assets');
    expect(Asset::find($asset->id))->toBeNull()
        ->and(Asset::withTrashed()->find($asset->id))->not->toBeNull();
});

it('does not find an asset by its numeric id', function () {
    $asset = createAsset($this->pc, ['specs' => ['cpu' => 'x']]);

    $this->actingAs($this->admin)->get("/assets/{$asset->id}")->assertNotFound();
});

it('lets a user without asset.create only view', function () {
    $user = userWithRole('user');
    $asset = createAsset($this->pc, ['specs' => ['cpu' => 'x']]);

    $this->actingAs($user)->get('/assets')->assertOk();
    $this->actingAs($user)->get("/assets/{$asset->ulid}")->assertOk();
    $this->actingAs($user)->post('/assets', assetPayload())->assertForbidden();
    $this->actingAs($user)->put("/assets/{$asset->ulid}", assetPayload())->assertForbidden();
    $this->actingAs($user)->delete("/assets/{$asset->ulid}")->assertForbidden();
});

describe('branch scope', function () {
    beforeEach(function () {
        $this->north = Branch::create(['code' => 'N', 'name' => 'North']);
        $this->south = Branch::create(['code' => 'S', 'name' => 'South']);
        // technician: asset.view + asset.update, no branch.all
        $this->tech = userWithRole('technician', ['branch_id' => $this->north->id]);

        $this->northAsset = createAsset($this->pc, ['name' => 'North PC', 'branch_id' => $this->north->id, 'specs' => ['cpu' => 'x']]);
        $this->southAsset = createAsset($this->pc, ['name' => 'South PC', 'branch_id' => $this->south->id, 'specs' => ['cpu' => 'x']]);
        $this->sharedAsset = createAsset($this->pc, ['name' => 'Shared PC', 'specs' => ['cpu' => 'x']]);
    });

    it('lists only the own branch and assets without a branch', function () {
        $this->actingAs($this->tech)->get('/assets')
            ->assertInertia(fn (Assert $page) => $page
                ->where('assets.total', 2)
                ->where('assets.data', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['North PC', 'Shared PC'])
                ->where('branches', [['id' => $this->north->id, 'name' => 'North']]));

        // branch.all sees every branch
        $this->actingAs($this->admin)->get('/assets')->assertInertia(fn (Assert $page) => $page->where('assets.total', 3));
    });

    it('forbids viewing or editing an asset of another branch', function () {
        $this->actingAs($this->tech)->get("/assets/{$this->southAsset->ulid}")->assertForbidden();
        $this->actingAs($this->tech)->put("/assets/{$this->southAsset->ulid}", assetPayload())->assertForbidden();
        $this->actingAs($this->tech)->get("/assets/{$this->northAsset->ulid}")->assertOk();
    });

    it('does not let the user move an asset to another branch', function () {
        $this->actingAs($this->tech)->put("/assets/{$this->northAsset->ulid}", assetPayload(['branch_id' => $this->south->id]))
            ->assertSessionHasErrors('branch_id');

        expect($this->northAsset->fresh()->branch_id)->toBe($this->north->id);
    });
});
