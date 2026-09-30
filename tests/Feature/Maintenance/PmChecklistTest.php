<?php

use App\Modules\Maintenance\Models\PmChecklist;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->category = createAssetCategory(['name' => 'Switch']);
});

function checklistPayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'PM สวิตช์',
        'asset_category_id' => test()->category->id,
        'items' => [
            ['key' => 'clean', 'label' => 'ทำความสะอาดพัดลม', 'type' => 'check'],
            ['key' => 'temp', 'label' => 'อุณหภูมิ', 'type' => 'number'],
        ],
    ];
}

it('manages checklists, one per category and one general', function () {
    $this->actingAs($this->admin)->post('/pm-checklists', checklistPayload())->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/pm-checklists', checklistPayload(['name' => 'Again']))->assertSessionHasErrors('asset_category_id');

    $this->actingAs($this->admin)->post('/pm-checklists', checklistPayload(['name' => 'ทั่วไป', 'asset_category_id' => null]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/pm-checklists', checklistPayload(['name' => 'ทั่วไป 2', 'asset_category_id' => null]))
        ->assertSessionHasErrors('asset_category_id');

    $this->actingAs($this->admin)->post('/pm-checklists', checklistPayload([
        'asset_category_id' => createAssetCategory()->id,
        'items' => [['key' => 'a', 'label' => 'A', 'type' => 'check'], ['key' => 'a', 'label' => 'B', 'type' => 'photo']],
    ]))->assertSessionHasErrors(['items.0.key', 'items.1.type']);
    $this->actingAs($this->admin)->post('/pm-checklists', checklistPayload(['asset_category_id' => createAssetCategory()->id, 'items' => []]))
        ->assertSessionHasErrors('items');

    $checklist = PmChecklist::where('name', 'PM สวิตช์')->first();
    expect($checklist->items)->toHaveCount(2);

    // editing keeps its own category
    $this->actingAs($this->admin)->put("/pm-checklists/{$checklist->id}", checklistPayload(['name' => 'PM สวิตช์ (ใหม่)']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/pm-checklists?search=ใหม่')
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Checklists/Index')
            ->where('checklists.total', 1)
            ->where('checklists.data.0.category', 'Switch')
            ->where('checklists.data.0.items_count', 2));
    $this->actingAs($this->admin)->get('/pm-checklists?category=general')
        ->assertInertia(fn (Assert $page) => $page->where('checklists.total', 1)->where('checklists.data.0.category', null));

    $this->actingAs($this->admin)->delete("/pm-checklists/{$checklist->id}")->assertRedirect('/pm-checklists');
    expect(PmChecklist::count())->toBe(1)->and(PmChecklist::withTrashed()->count())->toBe(2);
});

it('lets technicians read checklists but not change them', function () {
    $tech = userWithRole('technician');

    $this->actingAs($tech)->get('/pm-checklists')->assertOk();
    $this->actingAs($tech)->post('/pm-checklists', checklistPayload())->assertForbidden();
});
