<?php

use Inertia\Testing\AssertableInertia as Assert;

/*
 * The asset and part lists show 50 a page, with the page numbers to jump to.
 */

it('shows assets and parts 50 a page, with page numbers', function () {
    $admin = userWithRole('admin_company');
    $category = createAssetCategory();
    foreach (range(1, 51) as $n) {
        createAsset($category, ['name' => "Device {$n}"]);
    }
    foreach (range(1, 51) as $n) {
        createPart(['code' => sprintf('P-%03d', $n), 'name' => "Part {$n}"]);
    }

    $this->actingAs($admin)->get('/assets')->assertInertia(fn (Assert $page) => $page
        ->where('assets.per_page', 50)->where('assets.last_page', 2)->has('assets.data', 50)
        ->where('assets.links.2.label', '2'));
    $this->actingAs($admin)->get('/assets?page=2')->assertInertia(fn (Assert $page) => $page->has('assets.data', 1));
    $this->actingAs($admin)->get('/parts')->assertInertia(fn (Assert $page) => $page
        ->where('parts.per_page', 50)->where('parts.last_page', 2)->has('parts.data', 50));
});
