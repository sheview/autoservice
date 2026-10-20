<?php

use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Models\Asset;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;

beforeEach(function () {
    $this->tech = userWithRole('technician');
    $customer = createCustomer();
    $contract = createContract($customer);
    $this->asset = createAsset(createAssetCategory(), [
        'customer_id' => $customer->id, 'branch_id' => null, 'location' => 'ห้องประชุม', 'used_by' => 'คุณเก่า', 'ip_address' => '10.0.0.1',
    ]);
    $contract->contractAssets()->create(['asset_id' => $this->asset->id]);
    createPmTemplate(['name' => 'General', 'items' => [['key' => 'look', 'label' => 'Look', 'type' => 'check']]]);

    $plan = pmPlanWithAllRounds(['contract_id' => $contract->id, 'title' => 'PM', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->url = fn (PmVisitItem $item) => "/pm-visits/{$this->visit->ulid}/items/{$item->id}/asset";
});

it('shows the user and IP of the device on the work order and its sheet', function () {
    $visit = app(StartPmVisit::class)->handle($this->visit, $this->tech);

    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}")
        ->assertInertia(fn ($page) => $page->where('items.0.asset.used_by', 'คุณเก่า')->where('items.0.asset.ip_address', '10.0.0.1'));

    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}/sheet")
        ->assertOk()->assertSee('ชื่อผู้ใช้งาน')->assertSee('คุณเก่า')->assertSee('10.0.0.1');
});

it('corrects the asset register from the PM work order', function () {
    $visit = app(StartPmVisit::class)->handle($this->visit, $this->tech);
    $item = $visit->items()->first();

    $this->actingAs($this->tech)->put(($this->url)($item), ['ip_address' => 'not-an-ip'])->assertSessionHasErrors('ip_address');

    $this->actingAs($this->tech)->put(($this->url)($item), [
        'used_by' => ' คุณใหม่ ', 'ip_address' => '10.0.0.99', 'location' => 'ห้อง Server', 'property_no' => 'P-001',
        'name' => 'renamed', 'brand' => 'Other',
    ])->assertSessionHasNoErrors();

    $asset = $this->asset->fresh();
    expect($asset->only(['used_by', 'ip_address', 'location', 'property_no']))
        ->toEqual(['used_by' => 'คุณใหม่', 'ip_address' => '10.0.0.99', 'location' => 'ห้อง Server', 'property_no' => 'P-001'])
        ->and($asset->name)->toBe($this->asset->name)
        ->and($asset->brand)->toBe($this->asset->brand);

    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}/sheet")->assertSee('คุณใหม่')->assertSee('10.0.0.99');
});

it('keeps a property number unique and the device placed somewhere', function () {
    createAsset(createAssetCategory(), ['property_no' => 'TAKEN']);
    $visit = app(StartPmVisit::class)->handle($this->visit, $this->tech);
    $item = $visit->items()->first();

    $this->actingAs($this->tech)->put(($this->url)($item), ['property_no' => 'TAKEN'])->assertSessionHasErrors('property_no');
    // no branch, so it needs a location
    $this->actingAs($this->tech)->put(($this->url)($item), ['location' => ''])->assertSessionHasErrors('location');
    expect($this->asset->fresh()->location)->toBe('ห้องประชุม');
});

it('corrects the serial number shown, keeping the other serials of the asset', function () {
    $category = createAssetCategory(['requires_serial' => true]);
    $asset = app(SaveAsset::class)->handle(null, ['category_id' => $category->id, 'name' => 'Switch', 'status' => Asset::STATUS_IN_USE, 'location' => 'Rack'], ['SN-WRONG', 'SN-2']);
    $visit = app(StartPmVisit::class)->handle($this->visit, $this->tech);
    $item = $visit->items()->create(['asset_id' => $asset->id]);

    $this->actingAs($this->tech)->put(($this->url)($item), ['serial_number' => ' SN-RIGHT '])->assertSessionHasNoErrors();

    $asset = $asset->fresh();
    expect($asset->serial_number)->toBe('SN-RIGHT')
        ->and($asset->serials()->orderBy('id')->pluck('serial_number')->all())->toBe(['SN-RIGHT', 'SN-2'])
        ->and($asset->quantity)->toBe(2);

    // the only serial of a device category cannot be cleared
    $single = app(SaveAsset::class)->handle(null, ['category_id' => $category->id, 'name' => 'AP', 'status' => Asset::STATUS_IN_USE, 'location' => 'Rack'], ['SN-AP']);
    $apItem = $visit->items()->create(['asset_id' => $single->id]);
    $this->actingAs($this->tech)->put(($this->url)($apItem), ['serial_number' => ''])->assertSessionHasErrors('serial_number');
    expect($single->fresh()->serial_number)->toBe('SN-AP');
});

it('keeps a serial number on one asset only, and adds one where there was none', function () {
    createAsset(createAssetCategory(), ['serial_number' => 'SN-TAKEN']);
    $visit = app(StartPmVisit::class)->handle($this->visit, $this->tech);
    $item = $visit->items()->first();

    $this->actingAs($this->tech)->put(($this->url)($item), ['serial_number' => 'sn-taken'])->assertSessionHasErrors('serial_number');
    expect($this->asset->fresh()->serial_number)->toBeNull();

    $this->actingAs($this->tech)->put(($this->url)($item), ['serial_number' => 'SN-NEW'])->assertSessionHasNoErrors();
    expect($this->asset->fresh()->serial_number)->toBe('SN-NEW')
        ->and($this->asset->serials()->pluck('serial_number')->all())->toBe(['SN-NEW']);
    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}/sheet")->assertSee('SN-NEW');
});

it('corrects only while the round is being worked on', function () {
    app(StartPmVisit::class)->handle($this->visit, $this->tech);
    $item = $this->visit->items()->first();
    $this->visit->forceFill(['status' => 'completed'])->save();

    $this->actingAs($this->tech)->put(($this->url)($item), ['used_by' => 'x'])->assertSessionHasErrors('visit');
    expect($this->asset->fresh()->used_by)->toBe('คุณเก่า');
});

it('cannot correct an asset of another tenant', function () {
    $visit = app(StartPmVisit::class)->handle($this->visit, $this->tech);
    $item = $visit->items()->first();

    $other = createTenant('other');
    $outsider = asTenant($other, fn () => userWithRole('admin_company'));

    $this->actingAs($outsider)->put(($this->url)($item), ['used_by' => 'hacker'])->assertNotFound();
    expect(Asset::query()->find($this->asset->id)->used_by)->toBe('คุณเก่า');
});
