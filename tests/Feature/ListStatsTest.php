<?php

use App\Modules\Asset\Models\Asset;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The figures on top of the asset, request and part lists: counted as the lists filter, and
 * within what the user may see.
 */

it('counts assets as the list filters them, only those the user may see', function () {
    $admin = userWithRole('admin_company');
    $category = createAssetCategory();
    createAsset($category);
    createAsset($category, ['status' => Asset::STATUS_IN_REPAIR, 'warranty_expires_at' => now()->addDays(10)->toDateString()]);
    createAsset($category, ['status' => Asset::STATUS_RETIRED, 'warranty_expires_at' => now()->addYears(2)->toDateString()]);

    $this->actingAs($admin)->get('/assets')->assertInertia(fn (Assert $page) => $page
        ->where('stats', ['total' => 3, 'in_use' => 1, 'in_repair' => 1, 'warranty_expiring' => 1]));

    // Another company's assets are not counted.
    asTenant(createTenant('other'), fn () => createAsset(createAssetCategory()));
    $this->actingAs($admin)->get('/assets')->assertInertia(fn (Assert $page) => $page->where('stats.total', 3));
});

it('counts parts in stock, running low, out, and what went out this month', function () {
    $admin = userWithRole('admin_company');
    createPart(['min_qty' => 5, 'unit_cost' => 10000], 10); // satang
    $low = createPart(['min_qty' => 5, 'unit_cost' => 5000], 3);
    createPart(['min_qty' => 1]);
    $this->actingAs($admin)->post("/parts/{$low->id}/movements", ['type' => 'issue', 'quantity' => 2])->assertSessionHasNoErrors();

    $this->actingAs($admin)->get('/parts')->assertInertia(fn (Assert $page) => $page
        ->where('stats.active', 3)
        ->where('stats.low', 1)
        ->where('stats.out', 1)
        ->where('stats.out_this_month', 2)
        ->where('stats.stock_value', '1050.00')
        ->where('can.movements', true));
});

it('counts the open requests, and the queues only for who works them', function () {
    $this->actingAs(userWithRole('admin_company'))->get('/checkout-requests')->assertInertia(fn (Assert $page) => $page
        ->where('counts.open', 0)
        ->where('counts.approve', 0));

    $this->actingAs(userWithRole('user'))->get('/checkout-requests')->assertInertia(fn (Assert $page) => $page
        ->where('counts.open', 0)
        ->where('counts.approve', null));
});
