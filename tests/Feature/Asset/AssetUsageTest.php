<?php

use App\Modules\Asset\Models\Asset;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->category = createAssetCategory(['name' => 'PC', 'code_prefix' => 'PC']);
    $this->payload = [
        'category_id' => $this->category->id, 'name' => 'PC บัญชี', 'status' => 'in_use', 'owner' => 'company', 'location' => 'ชั้น 2',
    ];
});

it('keeps the network address and who uses the device', function () {
    $this->actingAs($this->admin)->post('/assets', [
        ...$this->payload,
        'ip_address' => '192.168.10.25', 'mac_address' => 'aa-bb-cc-dd-ee-0f', 'used_by' => 'สมหญิง ใจดี', 'department' => 'บัญชี',
    ])->assertSessionHasNoErrors();

    $asset = Asset::sole();
    expect($asset->only(['ip_address', 'mac_address', 'used_by', 'department']))
        ->toBe(['ip_address' => '192.168.10.25', 'mac_address' => 'AA:BB:CC:DD:EE:0F', 'used_by' => 'สมหญิง ใจดี', 'department' => 'บัญชี']);

    $this->actingAs($this->admin)->get("/assets/{$asset->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('asset.ip_address', '192.168.10.25')
        ->where('asset.used_by', 'สมหญิง ใจดี'));

    // found by IP, MAC or user
    foreach (['10.25', 'EE:0F', 'สมหญิง'] as $search) {
        $this->actingAs($this->admin)->get('/assets?search='.urlencode($search))
            ->assertInertia(fn (Assert $page) => $page->where('assets.total', 1));
    }
});

it('rejects an address that is not one', function () {
    $this->actingAs($this->admin)->post('/assets', [...$this->payload, 'ip_address' => '192.168.1.300', 'mac_address' => 'AA:BB:CC'])
        ->assertSessionHasErrors([
            'ip_address' => 'IP Address ไม่ถูกต้อง เช่น 192.168.1.10',
            'mac_address' => 'MAC Address ไม่ถูกต้อง เช่น AA:BB:CC:DD:EE:FF',
        ]);

    $this->actingAs($this->admin)->post('/assets', [...$this->payload, 'ip_address' => 'fe80::1'])->assertSessionHasNoErrors();
});
