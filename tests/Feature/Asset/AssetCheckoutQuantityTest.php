<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-01 10:00');
    $this->admin = userWithRole('admin_company');
    $this->tech = userWithRole('technician');
    // Bought by the lot: 24 cables in one asset.
    $this->cables = createAsset(createAssetCategory(['name' => 'Cable']), [
        'name' => 'สาย LAN Cat6', 'quantity' => 24, 'unit' => 'เส้น', 'status' => Asset::STATUS_SPARE,
    ]);
    $this->take = fn (int $quantity, string $type = 'issue') => $this->actingAs($this->tech)->post("/assets/{$this->cables->ulid}/checkouts", [
        'type' => $type, 'quantity' => $quantity, 'borrower_name' => 'Site team', 'due_on' => $type === 'loan' ? '2026-10-10' : null,
    ]);
    $this->left = fn () => $this->actingAs($this->admin)->get('/assets')->inertiaProps('assets.data.0.available');
});

it('shows available of total on the asset list and page', function () {
    $this->actingAs($this->admin)->get('/assets')->assertInertia(fn (Assert $page) => $page
        ->where('assets.data.0.quantity', 24)
        ->where('assets.data.0.available', 24)
        ->where('assets.data.0.unit', 'เส้น'));

    ($this->take)(5)->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/assets')->assertInertia(fn (Assert $page) => $page->where('assets.data.0.available', 19));
    $this->actingAs($this->admin)->get("/assets/{$this->cables->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('asset.available', 19)
        ->where('checkouts.available_quantity', 19)
        ->where('checkouts.available', true)
        ->where('checkouts.open.0.quantity', 5));
});

it('lends a lot to several people at once, never more than what is left', function () {
    ($this->take)(10)->assertSessionHasNoErrors();
    ($this->take)(10, 'loan')->assertSessionHasNoErrors();
    ($this->take)(5)->assertSessionHasErrors('quantity');
    ($this->take)(4)->assertSessionHasNoErrors();
    ($this->take)(1)->assertSessionHasErrors('type');

    expect(AssetCheckout::where('asset_id', $this->cables->id)->sum('quantity'))->toEqual(24)
        ->and(($this->left)())->toBe(0);

    $this->actingAs($this->admin)->get("/assets/{$this->cables->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('checkouts.open', 3)->where('checkouts.available', false));
});

it('puts the quantity back when a form is returned, rejected or cancelled', function () {
    ($this->take)(5);
    ($this->take)(3);
    ($this->take)(2);
    [$returned, $rejected, $cancelled] = AssetCheckout::orderBy('id')->get()->all();
    expect(($this->left)())->toBe(14);

    // approved: still held, and the lot stays spare while some of it is on the shelf
    $this->actingAs($this->admin)->post("/asset-checkouts/{$returned->ulid}/approve")->assertSessionHasNoErrors();
    expect(($this->left)())->toBe(14)
        ->and($this->cables->fresh()->status)->toBe(Asset::STATUS_SPARE);

    $this->actingAs($this->admin)->post("/asset-checkouts/{$returned->ulid}/return")->assertSessionHasNoErrors();
    expect(($this->left)())->toBe(19);

    $this->actingAs($this->admin)->post("/asset-checkouts/{$rejected->ulid}/reject", ['note' => 'ไม่จำเป็น'])->assertSessionHasNoErrors();
    expect(($this->left)())->toBe(22);

    $this->actingAs($this->tech)->post("/asset-checkouts/{$cancelled->ulid}/cancel")->assertSessionHasNoErrors();
    expect(($this->left)())->toBe(24);
});

it('marks the asset in use only when all of it is out, and spare when the last piece comes back', function () {
    ($this->take)(20);
    ($this->take)(4);
    [$big, $small] = AssetCheckout::orderBy('id')->get()->all();

    $this->actingAs($this->admin)->post("/asset-checkouts/{$big->ulid}/approve");
    expect($this->cables->fresh()->status)->toBe(Asset::STATUS_SPARE);
    $this->actingAs($this->admin)->post("/asset-checkouts/{$small->ulid}/approve");
    expect($this->cables->fresh()->status)->toBe(Asset::STATUS_IN_USE);

    $this->actingAs($this->admin)->post("/asset-checkouts/{$big->ulid}/return");
    expect($this->cables->fresh()->status)->toBe(Asset::STATUS_IN_USE);
    $this->actingAs($this->admin)->post("/asset-checkouts/{$small->ulid}/return");
    expect($this->cables->fresh()->status)->toBe(Asset::STATUS_SPARE);
});

it('offers a partly lent lot on the request page with what is left', function () {
    ($this->take)(20);

    $this->actingAs($this->tech)->get('/asset-checkouts/create?search=LAN')->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.units.0.available', 4)
        ->where('groups.0.units.0.quantity', 24));

    ($this->take)(4);
    $this->actingAs($this->tech)->get('/asset-checkouts/create?search=LAN')->assertInertia(fn (Assert $page) => $page->where('groups', []));
});
