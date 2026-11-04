<?php

use App\Modules\Asset\Models\Asset;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Ticking assets on the list and deleting them at once (e.g. rows an import brought in twice):
 * the company's admin only, soft-deleted like one by one, never another company's.
 */

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->category = createAssetCategory();
    $this->assets = collect(range(1, 3))->map(fn (int $n) => createAsset($this->category, ['name' => "Notebook {$n}"]));
});

it('deletes the ticked assets at once, leaving the others', function () {
    [$first, $second, $kept] = $this->assets->all();

    $this->actingAs($this->admin)->from('/assets?page=2')
        ->post('/assets/bulk-delete', ['assets' => [$first->ulid, $second->ulid]])
        ->assertRedirect('/assets?page=2')
        ->assertSessionHas('success', 'ลบทรัพย์สิน 2 รายการเรียบร้อยแล้ว');

    expect(Asset::pluck('id')->all())->toBe([$kept->id])
        // Soft-deleted: still there behind the scenes.
        ->and(Asset::onlyTrashed()->count())->toBe(2);
});

it('lets only the company admin delete at once and shows the boxes only to them', function () {
    $this->actingAs($this->admin)->get('/assets')->assertInertia(fn (Assert $page) => $page->where('can.bulkDelete', true));

    // Purchasing may delete one by one, but not many at once.
    $purchasing = userWithRole('purchasing');
    $this->actingAs($purchasing)->get('/assets')->assertInertia(fn (Assert $page) => $page->where('can.bulkDelete', false));
    $this->actingAs($purchasing)->post('/assets/bulk-delete', ['assets' => [$this->assets[0]->ulid]])->assertForbidden();
    $this->actingAs(userWithRole('helpdesk'))->post('/assets/bulk-delete', ['assets' => [$this->assets[0]->ulid]])->assertForbidden();

    expect(Asset::count())->toBe(3);
});

it('needs at least one asset ticked', function () {
    $this->actingAs($this->admin)->post('/assets/bulk-delete', ['assets' => []])->assertSessionHasErrors('assets');
});

it('never deletes the assets of another company', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, fn () => createAsset(createAssetCategory(), ['name' => 'Their notebook']));

    $this->actingAs($this->admin)->post('/assets/bulk-delete', ['assets' => [$theirs->ulid, $this->assets[0]->ulid]])
        ->assertSessionHas('success', 'ลบทรัพย์สิน 1 รายการเรียบร้อยแล้ว');

    asTenant($other, fn () => expect(Asset::sole()->is($theirs))->toBeTrue());
    expect(Asset::count())->toBe(2);
});
