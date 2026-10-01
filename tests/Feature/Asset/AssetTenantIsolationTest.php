<?php

use App\Modules\Asset\Models\Asset;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->other = createTenant('other');

    $this->mine = createAsset(createAssetCategory(['name' => 'Mine', 'code_prefix' => 'PC']), ['name' => 'My PC']);

    [$this->theirCategory, $this->theirAsset, $this->theirBranch] = asTenant($this->other, function () {
        $category = createAssetCategory(['name' => 'Theirs', 'code_prefix' => 'PC']);

        return [$category, createAsset($category, ['name' => 'Their PC']), Branch::create(['code' => 'X', 'name' => 'Their branch'])];
    });
});

it('lists only assets and categories of the own tenant', function () {
    $this->actingAs($this->admin)->get('/assets')
        ->assertInertia(fn (Assert $page) => $page
            ->where('assets.total', 1)
            ->where('assets.data.0.name', 'My PC')
            ->where('categories', fn ($categories) => collect($categories)->pluck('name')->all() === ['Mine']));

    $this->actingAs($this->admin)->get('/asset-categories')
        ->assertInertia(fn (Assert $page) => $page->where('categories.total', 1)->where('categories.data.0.name', 'Mine'));
});

it('numbers asset codes per tenant', function () {
    expect($this->mine->asset_code)->toBe('PC-00001')
        ->and($this->theirAsset->asset_code)->toBe('PC-00001');
});

it('returns 404 for an asset or category of another tenant', function () {
    $this->actingAs($this->admin)->get("/assets/{$this->theirAsset->ulid}")->assertNotFound();
    $this->actingAs($this->admin)->get("/assets/{$this->theirAsset->ulid}/edit")->assertNotFound();
    $this->actingAs($this->admin)->delete("/assets/{$this->theirAsset->ulid}")->assertNotFound();
    $this->actingAs($this->admin)->get("/asset-categories/{$this->theirCategory->id}/edit")->assertNotFound();

    expect(asTenant($this->other, fn () => Asset::whereKey($this->theirAsset->id)->exists()))->toBeTrue();
});

it('rejects a category or branch of another tenant', function () {
    $this->actingAs($this->admin)->post('/assets', [
        'category_id' => $this->theirCategory->id,
        'branch_id' => $this->theirBranch->id,
        'name' => 'Sneaky',
        'status' => 'in_use',
        'owner' => 'company',
        'location' => 'Stock',
    ])->assertSessionHasErrors(['category_id', 'branch_id']);
});

it('hides other tenants from raw queries (RLS)', function () {
    expect(DB::table('assets')->pluck('name')->all())->toBe(['My PC'])
        ->and(DB::table('asset_categories')->pluck('name')->all())->toBe(['Mine'])
        ->and(DB::table('asset_code_sequences')->count())->toBe(1);
});

it('rejects writing an asset into another tenant', function () {
    DB::table('assets')->insert([
        'ulid' => (string) str()->ulid(),
        'tenant_id' => $this->other->id,
        'category_id' => $this->theirCategory->id,
        'asset_code' => 'X-1',
        'name' => 'Sneaky',
        'status' => 'in_use',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class, 'row-level security');

it('does not export assets of another tenant', function () {
    $response = $this->actingAs($this->admin)->get('/assets/export')->assertOk();

    $rows = Excel::toCollection(null, $response->getFile()->getPathname())->first();
    expect($rows->slice(1)->pluck(1)->all())->toBe(['My PC']);
});
