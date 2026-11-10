<?php

use App\Modules\Asset\Models\AssetBrand;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-02-10 10:00');

    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician');
    $this->customer = createCustomer();
    $this->pc = createAssetCategory(['name' => 'PC', 'code_prefix' => 'PC']);
    // a notebook that sits in the PC category: the system picks the PC form for it, which is wrong
    $this->notebook = createAsset($this->pc, ['customer_id' => $this->customer->id, 'name' => 'Notebook']);
    $this->desktop = createAsset($this->pc, ['customer_id' => $this->customer->id, 'name' => 'Desktop']);

    $this->pcForm = createPmTemplate(['code' => 'PC', 'name' => 'ฟอร์ม PC', 'asset_category_id' => $this->pc->id, 'items' => [
        ['key' => 'fan', 'label' => 'พัดลม', 'type' => 'pass_fail'],
    ]]);
    $this->nbForm = PmChecklist::create(['code' => 'NB', 'name' => 'ฟอร์ม Notebook', 'items' => [
        ['key' => 'battery', 'label' => 'แบตเตอรี่', 'type' => 'pass_fail'],
        ['key' => 'screen', 'label' => 'จอ', 'type' => 'pass_fail'],
    ]]);

    $this->create = fn (array $data = []) => $this->actingAs($this->helpdesk)->post('/pm-visits', $data + [
        'customer_id' => $this->customer->id, 'title' => 'PM', 'due_on' => '2026-02-28',
        'assignee_id' => $this->tech->id, 'asset_ids' => [$this->notebook->id, $this->desktop->id],
    ]);
    $this->itemOf = fn (PmVisit $visit, $asset) => $visit->items()->where('asset_id', $asset->id)->sole();
});

it('shows why each form was picked and every form that may be picked instead, by name', function () {
    $this->actingAs($this->helpdesk)->get("/pm-visits/create?customer_id={$this->customer->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where("templates.{$this->notebook->id}", ['code' => 'PC', 'name' => 'ฟอร์ม PC', 'source' => 'category'])
            ->where('templateOptions', [
                ['code' => 'NB', 'name' => 'ฟอร์ม Notebook', 'questions' => 2, 'preview' => ['แบตเตอรี่', 'จอ']],
                ['code' => 'PC', 'name' => 'ฟอร์ม PC', 'questions' => 1, 'preview' => ['พัดลม']],
            ]));
});

it('opens a work order with a form picked by hand, and remembers it for the asset when asked', function () {
    ($this->create)(['templates' => [$this->notebook->id => 'NB', $this->desktop->id => null], 'remember_templates' => true])->assertSessionHasNoErrors();

    $visit = PmVisit::sole();
    expect(($this->itemOf)($visit, $this->notebook)->only(['template_code', 'pm_checklist_id']))->toBe(['template_code' => 'NB', 'pm_checklist_id' => $this->nbForm->id])
        ->and(($this->itemOf)($visit, $this->notebook)->checklist)->toHaveCount(2)
        ->and(($this->itemOf)($visit, $this->desktop)->template_code)->toBe('PC')
        ->and(PmTemplateAssignment::where('asset_id', $this->notebook->id)->pluck('template_code')->all())->toBe(['NB'])
        ->and(PmTemplateAssignment::where('asset_id', $this->desktop->id)->exists())->toBeFalse();

    // the next work order gets it by itself, and says why
    $this->actingAs($this->helpdesk)->get("/pm-visits/create?customer_id={$this->customer->id}")
        ->assertInertia(fn (Assert $page) => $page->where("templates.{$this->notebook->id}.source", 'asset')->where("templates.{$this->notebook->id}.code", 'NB'));
});

it('keeps a form picked by hand to this work order unless asked to remember it', function () {
    ($this->create)(['templates' => [$this->notebook->id => 'NB'], 'remember_templates' => false])->assertSessionHasNoErrors();

    expect(($this->itemOf)(PmVisit::sole(), $this->notebook)->template_code)->toBe('NB')
        ->and(PmTemplateAssignment::whereNotNull('asset_id')->exists())->toBeFalse();
});

it('refuses a form that is not published', function () {
    PmChecklist::create(['code' => 'DRAFT', 'name' => 'ร่าง', 'status' => PmChecklist::STATUS_DRAFT, 'items' => []]);

    ($this->create)(['templates' => [$this->notebook->id => 'DRAFT']])->assertSessionHasErrors('templates');
    ($this->create)(['templates' => [$this->notebook->id => 'NOPE']])->assertSessionHasErrors('templates');
    expect(PmVisit::count())->toBe(0);
});

it('changes the form of equipment on a work order while nothing is recorded on it', function () {
    ($this->create)();
    $visit = PmVisit::sole();
    $url = "/pm-visits/{$visit->ulid}";
    $item = ($this->itemOf)($visit, $this->notebook);
    $item->update(['draft' => ['answers' => ['fan' => 'pass']]]);

    $this->actingAs($this->helpdesk)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('templateOptions', fn ($options) => collect($options)->pluck('code')->all() === ['NB', 'PC'])
        ->where('items.0.template', ['code' => 'PC', 'name' => 'ฟอร์ม PC'])
        ->where('items.0.template_changeable', true));

    $this->actingAs($this->helpdesk)->put("{$url}/items/{$item->id}/template", ['template_code' => 'NB', 'remember' => false])->assertSessionHasNoErrors();

    $item->refresh();
    expect($item->only(['template_code', 'template_version', 'pm_checklist_id', 'draft']))
        ->toBe(['template_code' => 'NB', 'template_version' => 1, 'pm_checklist_id' => $this->nbForm->id, 'draft' => null])
        ->and(collect($item->checklist)->pluck('key')->all())->toBe(['battery', 'screen'])
        ->and(PmTemplateAssignment::whereNotNull('asset_id')->exists())->toBeFalse();
    $event = PmVisitEvent::where('pm_visit_id', $visit->id)->where('type', PmVisitEvent::TYPE_TEMPLATE_CHANGED)->sole();
    expect($event->data)->toMatchArray(['from' => 'PC', 'to' => 'NB', 'remember' => false]);

    // with remember, the asset keeps it, and a plan that named its own form for it is told too
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => createContract($this->customer)->id, 'title' => 'PM', 'interval_months' => 6]);
    PmPlanAsset::create(['pm_plan_id' => $plan->id, 'asset_id' => $this->notebook->id, 'template_code' => 'PC']);
    $this->actingAs($this->helpdesk)->put("{$url}/items/{$item->id}/template", ['template_code' => 'NB', 'remember' => true])->assertSessionHasNoErrors();
    expect(PmTemplateAssignment::where('asset_id', $this->notebook->id)->pluck('template_code')->all())->toBe(['NB'])
        ->and(PmPlanAsset::where('asset_id', $this->notebook->id)->value('template_code'))->toBe('NB');
});

it('lets the technician doing the work change a wrong form on site, offering the fitting ones first', function () {
    // another form assigned to a brand of the same category: fits a PC, offered after the category's own
    $brand = AssetBrand::create(['name' => 'Dell']);
    PmTemplateAssignment::create(['template_code' => 'NB', 'asset_category_id' => $this->pc->id, 'asset_brand_id' => $brand->id]);
    ($this->create)();
    $visit = PmVisit::sole();
    $item = ($this->itemOf)($visit, $this->notebook);

    $this->actingAs($this->tech)->get("/pm-work/{$visit->ulid}/items/{$item->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Field/Item')
        ->where('item.template', ['code' => 'PC', 'name' => 'ฟอร์ม PC'])
        ->where('item.template_changeable', true)
        ->where('item.template_suggested', ['PC', 'NB'])
        ->where('templateOptions', fn ($options) => collect($options)->pluck('code')->all() === ['NB', 'PC']));
    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('items.0.template_changeable', true)
        ->where("templateSuggested.{$item->id}", ['PC', 'NB']));
    // a partial reload of something else does not load the forms
    $this->actingAs($this->tech)->get("/pm-visits/{$visit->ulid}", [
        'X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'Maintenance/Visits/Show', 'X-Inertia-Partial-Data' => 'events',
    ])->assertOk()->assertJsonMissingPath('props.templateSuggested')->assertJsonMissingPath('props.templateOptions');

    $this->actingAs($this->tech)->put("/pm-visits/{$visit->ulid}/items/{$item->id}/template", ['template_code' => 'NB', 'remember' => true])
        ->assertSessionHasNoErrors();
    expect($item->fresh()->template_code)->toBe('NB')
        ->and(PmTemplateAssignment::where('asset_id', $this->notebook->id)->pluck('template_code')->all())->toBe(['NB']);

    // recorded: no longer offered
    $this->actingAs($this->tech)->post("/pm-visits/{$visit->ulid}/start")->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->put("/pm-visits/{$visit->ulid}/items/{$item->id}", ['result' => 'passed', 'answers' => ['battery' => 'pass', 'screen' => 'pass']])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->get("/pm-work/{$visit->ulid}/items/{$item->id}")->assertInertia(fn (Assert $page) => $page->where('item.template_changeable', false));
});

it('never changes the form of recorded work, nor on a work order handed in', function () {
    ($this->create)();
    $visit = PmVisit::sole();
    $url = "/pm-visits/{$visit->ulid}";
    $this->actingAs($this->tech)->post("{$url}/start")->assertSessionHasNoErrors();
    $notebook = ($this->itemOf)($visit, $this->notebook);
    $desktop = ($this->itemOf)($visit, $this->desktop);

    $this->actingAs($this->tech)->put("{$url}/items/{$notebook->id}", ['result' => 'passed', 'answers' => ['fan' => 'pass']])->assertSessionHasNoErrors();
    $this->actingAs($this->helpdesk)->put("{$url}/items/{$notebook->id}/template", ['template_code' => 'NB'])->assertSessionHasErrors('template_code');
    expect($notebook->fresh()->template_code)->toBe('PC');
    $this->actingAs($this->helpdesk)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('items', fn ($items) => collect($items)->firstWhere('id', $notebook->id)['template_changeable'] === false));

    // a form that is not published
    $this->actingAs($this->helpdesk)->put("{$url}/items/{$desktop->id}/template", ['template_code' => 'NOPE'])->assertSessionHasErrors('template_code');

    // who may not change the work order's equipment
    $this->actingAs(userWithRole('technician'))->put("{$url}/items/{$desktop->id}/template", ['template_code' => 'NB'])->assertForbidden();

    // handed in: fixed
    PmVisitItem::where('pm_visit_id', $visit->id)->update(['result' => 'passed']);
    $this->actingAs($this->tech)->post("{$url}/submit", ['technician_signature' => signaturePng()])->assertSessionHasNoErrors();
    PmVisitItem::whereKey($desktop->id)->update(['result' => 'pending']);
    $this->actingAs($this->helpdesk)->put("{$url}/items/{$desktop->id}/template", ['template_code' => 'NB'])->assertSessionHasErrors('template_code');
});

it('gives the remembered form to the other work orders still to do, never to recorded or drafted work', function () {
    foreach (range(1, 5) as $n) {
        ($this->create)(['title' => "PM {$n}"])->assertSessionHasNoErrors();
    }
    [$here, $todo, $recorded, $drafted, $closed] = PmVisit::orderBy('id')->get()->all();
    $item = fn (PmVisit $visit) => ($this->itemOf)($visit, $this->notebook);
    // work recorded on the notebook, work drafted on it, and a work order already done
    $this->actingAs($this->tech)->post("/pm-visits/{$recorded->ulid}/start")->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->put("/pm-visits/{$recorded->ulid}/items/{$item($recorded)->id}", ['result' => 'passed', 'answers' => ['fan' => 'pass']])
        ->assertSessionHasNoErrors();
    $item($drafted)->update(['draft' => ['answers' => ['fan' => 'pass']]]);
    $closed->update(['status' => PmVisit::STATUS_CLOSED]);

    // not remembered: only this work order
    $this->actingAs($this->tech)->put("/pm-visits/{$here->ulid}/items/{$item($here)->id}/template", ['template_code' => 'NB', 'remember' => false])
        ->assertSessionHasNoErrors();
    expect($item($todo)->template_code)->toBe('PC');

    $item($here)->update(['template_code' => 'PC']);
    $this->actingAs($this->tech)->put("/pm-visits/{$here->ulid}/items/{$item($here)->id}/template", ['template_code' => 'NB', 'remember' => true])
        ->assertSessionHasNoErrors();

    expect($item($todo)->only(['template_code', 'pm_checklist_id']))->toBe(['template_code' => 'NB', 'pm_checklist_id' => $this->nbForm->id])
        ->and($item($todo)->checklist)->toHaveCount(2)
        // recorded, drafted and done keep the form they were done with
        ->and($item($recorded)->template_code)->toBe('PC')
        ->and($item($recorded)->answers)->toBe(['fan' => 'pass'])
        ->and($item($drafted)->template_code)->toBe('PC')
        ->and($item($drafted)->draft)->not->toBeEmpty()
        ->and($item($closed)->template_code)->toBe('PC')
        // the desktop is not touched
        ->and(($this->itemOf)($todo, $this->desktop)->template_code)->toBe('PC');

    // each work order changed says so in its history
    expect(PmVisitEvent::where('pm_visit_id', $todo->id)->where('type', PmVisitEvent::TYPE_TEMPLATE_CHANGED)->sole()->data['because_of'])->toBe($here->visit_no)
        ->and(PmVisitEvent::where('pm_visit_id', $recorded->id)->where('type', PmVisitEvent::TYPE_TEMPLATE_CHANGED)->exists())->toBeFalse();
});

it('does not reach a form or a work order of another tenant', function () {
    $other = createTenant('other');
    asTenant($other, fn () => PmChecklist::create(['code' => 'THEIRS', 'name' => 'ของบริษัทอื่น', 'items' => []]));
    [$theirVisit, $theirItem] = asTenant($other, function () {
        $customer = createCustomer();
        $asset = createAsset(createAssetCategory(['name' => 'X', 'code_prefix' => 'X']), ['customer_id' => $customer->id]);
        $visit = PmVisit::create(['customer_id' => $customer->id, 'visit_no' => 'PM-X', 'title' => 'X', 'due_on' => '2026-02-28', 'status' => PmVisit::STATUS_SCHEDULED]);

        return [$visit, $visit->items()->create(['asset_id' => $asset->id])];
    });

    ($this->create)(['templates' => [$this->notebook->id => 'THEIRS']])->assertSessionHasErrors('templates');
    ($this->create)();
    $visit = PmVisit::sole();
    $item = ($this->itemOf)($visit, $this->notebook);
    $this->actingAs($this->helpdesk)->put("/pm-visits/{$visit->ulid}/items/{$item->id}/template", ['template_code' => 'THEIRS'])->assertSessionHasErrors('template_code');

    // their work order and item are not found here
    $this->actingAs($this->helpdesk)->put("/pm-visits/{$theirVisit->ulid}/items/{$theirItem->id}/template", ['template_code' => 'NB'])->assertNotFound();
    $this->actingAs($this->helpdesk)->put("/pm-visits/{$visit->ulid}/items/{$theirItem->id}/template", ['template_code' => 'NB'])->assertNotFound();
    expect(asTenant($other, fn () => PmTemplateAssignment::whereNotNull('asset_id')->exists()))->toBeFalse();
});
