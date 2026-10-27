<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
});

it('offers the units, departments and sub-types used most on the asset form, sub-types per category', function () {
    $switch = createAssetCategory(['name' => 'Switch', 'code_prefix' => 'SW']);
    $cable = createAssetCategory(['name' => 'Cable', 'code_prefix' => 'CB']);
    createAsset($switch, ['department' => 'บัญชี', 'subtype' => 'Switching HUB']);
    createAsset($switch, ['department' => 'บัญชี', 'subtype' => 'Switching HUB']);
    createAsset($switch, ['department' => 'IT', 'subtype' => 'PoE Switch']);
    createAsset($cable, ['unit' => 'ม้วน', 'subtype' => 'LAN Cat6']);

    $this->actingAs($this->admin)->get('/assets/create')->assertInertia(fn (Assert $page) => $page
        ->where('picks.departments', ['บัญชี', 'IT'])
        ->where('picks.units', fn ($units) => $units[0] === 'ม้วน' && in_array('เครื่อง', $units->all(), true))
        ->where('picks.subtypes.0', ['category_id' => $switch->id, 'name' => 'Switching HUB'])
        ->has('picks.subtypes', 3));
});

it('offers the units and brands used most on the part form, common units for a new company', function () {
    $this->actingAs($this->admin)->get('/parts/create')->assertInertia(fn (Assert $page) => $page
        ->where('picks.brands', [])->where('picks.units.0', 'ชิ้น'));

    createPart(['code' => 'P1', 'unit' => 'กล่อง', 'brand' => 'Dell']);
    createPart(['code' => 'P2', 'unit' => 'กล่อง', 'brand' => 'Dell']);
    createPart(['code' => 'P3', 'unit' => 'ลัง', 'brand' => 'HP']);
    $part = createPart(['code' => 'P4', 'unit' => 'ลัง']);

    $this->actingAs($this->admin)->get("/parts/{$part->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('picks.brands', ['Dell', 'HP'])
        ->where('picks.units', fn ($units) => array_slice($units->all(), 0, 2) === ['กล่อง', 'ลัง'] || array_slice($units->all(), 0, 2) === ['ลัง', 'กล่อง']));
});

it('never offers values of another company', function () {
    asTenant(createTenant('other'), function () {
        createAsset(createAssetCategory(), ['department' => 'ฝ่ายของเขา']);
        createPart(['code' => 'X1', 'unit' => 'หน่วยของเขา', 'brand' => 'ยี่ห้อของเขา']);
    });

    $this->actingAs($this->admin)->get('/assets/create')->assertInertia(fn (Assert $page) => $page
        ->where('picks.departments', [])->where('picks.units', fn ($units) => ! in_array('หน่วยของเขา', $units->all(), true)));
    $this->actingAs($this->admin)->get('/parts/create')->assertInertia(fn (Assert $page) => $page
        ->where('picks.brands', [])->where('picks.units', fn ($units) => ! in_array('หน่วยของเขา', $units->all(), true)));
});
