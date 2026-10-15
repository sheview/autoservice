<?php

use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Support\StandardCategories;
use App\Modules\Tenancy\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

it('gives a new company the standard categories, which it may then change', function () {
    $this->actingAs(createSuperadmin())->post('/platform/tenants', [
        'name' => 'บริษัท ใหม่ จำกัด', 'subdomain' => 'newco', 'status' => 'active',
        'admin_name' => 'คุณสมชาย', 'admin_email' => 'admin@newco.test',
        'admin_password' => 'Password-123', 'admin_password_confirmation' => 'Password-123',
    ])->assertSessionHasNoErrors();

    $standard = collect(StandardCategories::all());
    asTenant(Tenant::where('subdomain', 'newco')->sole(), function () use ($standard) {
        expect(AssetCategory::orderBy('id')->pluck('code_prefix')->all())->toBe($standard->pluck('code_prefix')->all());

        $switch = AssetCategory::where('code_prefix', 'SW')->sole();
        expect($switch->only(['name', 'service_line', 'asset_type']))->toBe(['name' => 'สวิตช์', 'service_line' => 'network', 'asset_type' => 'hardware'])
            ->and(collect($switch->spec_fields)->pluck('key')->all())->toBe(['ports', 'layer', 'poe', 'mgmt_ip', 'firmware'])
            ->and(AssetCategory::where('code_prefix', 'LIC')->value('asset_type'))->toBe('software');
    });

    // Only that company: ours is untouched.
    expect(AssetCategory::count())->toBe(0);
});

it('adds only the standard categories the company is missing, never doubling its own', function () {
    $admin = userWithRole('admin_company');
    // Own versions: one with a standard name, one with a standard prefix, and one of its own.
    createAssetCategory(['name' => 'สวิตช์', 'code_prefix' => 'NSW']);
    createAssetCategory(['name' => 'คอมพิวเตอร์ PC', 'code_prefix' => 'PC']);
    createAssetCategory(['name' => 'โดรนสำรวจ', 'code_prefix' => 'DRN']);
    $count = count(StandardCategories::all());

    $this->actingAs($admin)->get('/asset-categories')->assertInertia(fn (Assert $page) => $page
        ->where('standardMissing', fn ($names) => count($names) === $count - 2
            && ! collect($names)->contains('สวิตช์') && ! collect($names)->contains('คอมพิวเตอร์ตั้งโต๊ะ')));

    $this->actingAs($admin)->post('/asset-categories/standard')->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'เพิ่มหมวดมาตรฐาน '.($count - 2).' หมวดแล้ว');
    expect(AssetCategory::count())->toBe($count + 1)
        ->and(AssetCategory::where('name', 'สวิตช์')->value('code_prefix'))->toBe('NSW');

    // Nothing left to add: the offer is gone and a second click adds nothing.
    $this->actingAs($admin)->get('/asset-categories')->assertInertia(fn (Assert $page) => $page->where('standardMissing', []));
    $this->actingAs($admin)->post('/asset-categories/standard')->assertSessionHas('success', 'เพิ่มหมวดมาตรฐาน 0 หมวดแล้ว');
    expect(AssetCategory::count())->toBe($count + 1);
});

it('lets only who may create categories add the standard ones', function () {
    $helpdesk = userWithRole('helpdesk'); // views categories, may not change them

    $this->actingAs($helpdesk)->get('/asset-categories')->assertInertia(fn (Assert $page) => $page->where('standardMissing', []));
    $this->actingAs($helpdesk)->post('/asset-categories/standard')->assertForbidden();
    expect(AssetCategory::count())->toBe(0);
});
