<?php

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Support\PmTemplateResolver;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = userWithRole('admin_company');
    $this->category = createAssetCategory(['name' => 'Switch']);
});

function templatePayload(array $overrides = []): array
{
    return $overrides + [
        'code' => 'SW-PM',
        'name' => 'PM สวิตช์',
        'service_line' => 'network',
        'layout' => 'per_asset',
        'blocks' => ['acknowledgement'],
        'assignments' => [['asset_category_id' => test()->category->id]],
        'items' => [
            ['key' => 'clean', 'label' => 'ทำความสะอาดพัดลม', 'type' => 'pass_fail'],
            ['key' => 'temp', 'label' => 'อุณหภูมิ', 'type' => 'number', 'unit' => '°C', 'max' => 45],
        ],
    ];
}

it('creates a template as a draft, publishes it, and edits it only through a new version', function () {
    $this->actingAs($this->admin)->post('/pm-checklists', templatePayload())->assertSessionHasNoErrors();
    $draft = PmChecklist::where('code', 'SW-PM')->sole();
    expect($draft->status)->toBe(PmChecklist::STATUS_DRAFT)->and($draft->version)->toBe(1)
        ->and($draft->blocks)->toMatchArray(['acknowledgement' => true, 'parts_usage' => false])
        ->and(PmTemplateAssignment::where('template_code', 'SW-PM')->sole()->asset_category_id)->toBe($this->category->id);

    // a draft is not used yet
    $asset = createAsset($this->category);
    expect(PmTemplateResolver::load()->for($asset->only(['id', 'category_id', 'asset_brand_id', 'asset_model_id'])))->toBeNull();

    $this->actingAs($this->admin)->put("/pm-checklists/{$draft->id}", templatePayload(['code' => 'OTHER-CODE', 'name' => 'PM สวิตช์ v1']))
        ->assertSessionHasErrors('code');
    $this->actingAs($this->admin)->put("/pm-checklists/{$draft->id}", collect(templatePayload(['name' => 'PM สวิตช์ v1']))->except('code')->all())
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/pm-checklists/{$draft->id}/publish")->assertSessionHasNoErrors();
    expect($draft->fresh()->status)->toBe(PmChecklist::STATUS_PUBLISHED)
        ->and(PmTemplateResolver::load()->for($asset->only(['id', 'category_id', 'asset_brand_id', 'asset_model_id']))->id)->toBe($draft->id);

    // a published version is not changed: "edit" makes the next version as a draft
    $this->actingAs($this->admin)->put("/pm-checklists/{$draft->id}", collect(templatePayload(['name' => 'x']))->except('code')->all())
        ->assertSessionHasErrors('template');
    $this->actingAs($this->admin)->post("/pm-checklists/{$draft->id}/new-version")->assertRedirect();
    $v2 = PmChecklist::where('code', 'SW-PM')->where('version', 2)->sole();
    expect($v2->status)->toBe(PmChecklist::STATUS_DRAFT)->and($v2->items)->toBe($draft->fresh()->items);
    // asking again opens the same draft
    $this->actingAs($this->admin)->post("/pm-checklists/{$draft->id}/new-version");
    expect(PmChecklist::where('code', 'SW-PM')->count())->toBe(2);

    $this->actingAs($this->admin)->put("/pm-checklists/{$v2->id}", collect(templatePayload(['name' => 'PM สวิตช์ v2', 'publish' => true]))->except('code')->all())
        ->assertSessionHasNoErrors();
    expect($draft->fresh()->status)->toBe(PmChecklist::STATUS_ARCHIVED)
        ->and($v2->fresh()->status)->toBe(PmChecklist::STATUS_PUBLISHED)
        ->and(PmTemplateResolver::load()->for($asset->only(['id', 'category_id', 'asset_brand_id', 'asset_model_id']))->version)->toBe(2);

    // the list shows one row per code, its newest version
    $this->actingAs($this->admin)->get('/pm-checklists?search=SW-')
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Checklists/Index')
            ->where('checklists.total', 1)
            ->where('checklists.data.0.version', 2)
            ->where('checklists.data.0.tied_to', ['Switch'])
            ->where('checklists.data.0.items_count', 2));
    $this->actingAs($this->admin)->get('/pm-checklists?status=draft')->assertInertia(fn (Assert $page) => $page->where('checklists.total', 0));
    $this->actingAs($this->admin)->get("/pm-checklists?category={$this->category->id}")->assertInertia(fn (Assert $page) => $page->where('checklists.total', 1));

    // only a draft can be deleted
    $this->actingAs($this->admin)->delete("/pm-checklists/{$v2->id}")->assertSessionHasErrors('template');
});

it('copies a template to a new code as a draft tied to nothing', function () {
    $this->actingAs($this->admin)->post('/pm-checklists', templatePayload(['publish' => true]))->assertSessionHasNoErrors();
    $source = PmChecklist::where('code', 'SW-PM')->sole();

    $this->actingAs($this->admin)->post("/pm-checklists/{$source->id}/copy")->assertRedirect();
    $this->actingAs($this->admin)->post("/pm-checklists/{$source->id}/copy")->assertRedirect();

    expect(PmChecklist::where('code', 'SW-PM-COPY')->sole())->status->toBe(PmChecklist::STATUS_DRAFT)
        ->and(PmChecklist::where('code', 'SW-PM-COPY2')->exists())->toBeTrue()
        ->and(PmTemplateAssignment::where('template_code', 'SW-PM-COPY')->exists())->toBeFalse();

    $copy = PmChecklist::where('code', 'SW-PM-COPY2')->sole();
    $this->actingAs($this->admin)->delete("/pm-checklists/{$copy->id}")->assertRedirect('/pm-checklists');
    expect(PmChecklist::where('code', 'SW-PM-COPY2')->exists())->toBeFalse();
});

it('checks the fields of a template and how they fit together', function () {
    $post = fn (array $items, array $extra = []) => $this->actingAs($this->admin)->post('/pm-checklists', templatePayload(['code' => 'T'.uniqid(), 'items' => $items, ...$extra]));

    $post([['key' => 'a', 'label' => 'A', 'type' => 'pass_fail'], ['key' => 'a', 'label' => 'B', 'type' => 'nope']])
        ->assertSessionHasErrors(['items.0.key', 'items.1.type']);
    $post([])->assertSessionHasErrors('items');
    $post([['key' => 'pick', 'label' => 'Pick', 'type' => 'select', 'options' => []]])->assertSessionHasErrors('items');
    $post([['key' => 'volt', 'label' => 'Volt', 'type' => 'number', 'min' => 240, 'max' => 210]])->assertSessionHasErrors('items');
    $post([['key' => 'cells', 'label' => 'Cells', 'type' => 'repeat_table', 'rows' => ['from' => 'count'], 'columns' => [['key' => 'v', 'label' => 'V', 'type' => 'number']]]])
        ->assertSessionHasErrors('items');
    $post([['key' => 't', 'label' => 'T', 'type' => 'measure_table', 'rows' => [['key' => 'r', 'label' => 'R']], 'columns' => [['key' => 'c', 'label' => 'C'], ['key' => 'c', 'label' => 'D']]]])
        ->assertSessionHasErrors('items');
    $post([['key' => 'a', 'label' => 'A', 'type' => 'pass_fail']], ['code' => 'bad code'])->assertSessionHasErrors('code');

    $post([
        ['key' => 'count', 'label' => 'Battery total', 'type' => 'number'],
        ['key' => 'cells', 'label' => 'Cells', 'type' => 'repeat_table', 'rows' => ['from' => 'count', 'max' => 40], 'columns' => [['key' => 'v', 'label' => 'V', 'type' => 'number', 'min' => 12]]],
        ['key' => 'pick', 'label' => 'Pick', 'type' => 'checkbox_group', 'options' => ['A', 'B', 'A', ' ']],
    ], ['code' => 'BATT'])->assertSessionHasNoErrors();
    expect(PmChecklist::where('code', 'BATT')->sole()->items[2]['options'])->toBe(['A', 'B']);

    // the code is unique within the company only
    $this->actingAs($this->admin)->post('/pm-checklists', templatePayload(['code' => 'batt']))->assertSessionHasErrors('code');
    $other = createTenant('other');
    asTenant($other, fn () => createPmTemplate(['code' => 'OTHER']));
    $this->actingAs($this->admin)->post('/pm-checklists', templatePayload(['code' => 'OTHER']))->assertSessionHasNoErrors();
});

it('ties a template to categories, brands within a category and models of the company only', function () {
    $dell = createAsset($this->category, ['brand' => 'Dell', 'model' => 'N1524']);
    $theirCategory = asTenant(createTenant('other'), fn () => createAssetCategory()->id);

    $this->actingAs($this->admin)->post('/pm-checklists', templatePayload(['assignments' => [['asset_category_id' => $theirCategory]]]))
        ->assertSessionHasErrors('assignments.0.asset_category_id');

    $this->actingAs($this->admin)->post('/pm-checklists', templatePayload(['publish' => true, 'assignments' => [
        ['asset_category_id' => $this->category->id, 'asset_brand_id' => $dell->asset_brand_id],
        ['asset_model_id' => $dell->asset_model_id],
        ['asset_model_id' => $dell->asset_model_id],
        ['asset_category_id' => null],
    ]]))->assertSessionHasNoErrors();

    expect(PmTemplateAssignment::where('template_code', 'SW-PM')->count())->toBe(3)
        ->and(PmTemplateResolver::load()->for($dell->only(['id', 'category_id', 'asset_brand_id', 'asset_model_id']))?->code)->toBe('SW-PM');
});

it('lets technicians read templates but not change them', function () {
    $tech = userWithRole('technician');
    $template = createPmTemplate(['code' => 'SW']);

    $this->actingAs($tech)->get('/pm-checklists')->assertOk();
    $this->actingAs($tech)->get("/pm-checklists/{$template->id}/edit")->assertOk();
    $this->actingAs($tech)->post('/pm-checklists', templatePayload())->assertForbidden();
    $this->actingAs($tech)->post("/pm-checklists/{$template->id}/new-version")->assertForbidden();
    $this->actingAs($tech)->post("/pm-checklists/{$template->id}/copy")->assertForbidden();
    $this->actingAs(userWithRole('customer_it', ['customer_id' => createCustomer()->id]))->get('/pm-checklists')->assertForbidden();
});

it('keeps templates to their own company', function () {
    $theirs = asTenant(createTenant('other'), fn () => createPmTemplate(['code' => 'X', 'status' => PmChecklist::STATUS_DRAFT]));

    $this->actingAs($this->admin)->get("/pm-checklists/{$theirs->id}/edit")->assertNotFound();
    $this->actingAs($this->admin)->post("/pm-checklists/{$theirs->id}/publish")->assertNotFound();
    $this->actingAs($this->admin)->post("/pm-checklists/{$theirs->id}/copy")->assertNotFound();
    $this->actingAs($this->admin)->delete("/pm-checklists/{$theirs->id}")->assertNotFound();
});
