<?php

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-02-10 10:00');

    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);
    $this->ups = createAssetCategory(['name' => 'UPS', 'code_prefix' => 'UPS']);
    $this->upsA = createAsset($this->ups, ['customer_id' => $this->customer->id, 'name' => 'UPS A']);
    $this->upsB = createAsset($this->ups, ['customer_id' => $this->customer->id, 'name' => 'UPS B']);
    $this->theirs = createAsset($this->ups, ['customer_id' => createCustomer()->id, 'name' => 'Other UPS']);

    PmChecklist::create(['name' => 'UPS PM', 'asset_category_id' => $this->ups->id, 'items' => [
        ['key' => 'battery', 'label' => 'แบตเตอรี่', 'type' => 'pass_fail', 'section' => 'ภายใน', 'required' => true],
        ['key' => 'volt', 'label' => 'แรงดันขาออก', 'type' => 'measure', 'unit' => 'V', 'min' => 210, 'max' => 240],
        ['key' => 'look', 'label' => 'ข้อสังเกต', 'type' => 'text'],
    ]]);

    $this->create = fn (array $data = []) => $this->actingAs($this->helpdesk)->post('/pm-visits', $data + [
        'customer_id' => $this->customer->id, 'title' => 'PM UPS ห้อง Server', 'due_on' => '2026-02-28',
        'assignee_id' => $this->tech->id, 'asset_ids' => [$this->upsA->id],
    ]);
});

it('makes a work order by hand, as a draft, then schedules it', function () {
    ($this->create)(['draft' => true])->assertSessionHasNoErrors();

    $visit = PmVisit::sole();
    expect($visit->only(['status', 'title', 'pm_plan_id', 'contract_id', 'round', 'created_by']))->toBe([
        'status' => PmVisit::STATUS_DRAFT, 'title' => 'PM UPS ห้อง Server', 'pm_plan_id' => null, 'contract_id' => null,
        'round' => null, 'created_by' => $this->helpdesk->id,
    ])
        ->and($visit->visit_no)->toBe('PM-2569-00001')
        ->and($visit->items()->pluck('asset_id')->all())->toBe([$this->upsA->id])
        // each item has its own copy of the category's checklist
        ->and($visit->items()->first()->checklist)->toHaveCount(3);

    $this->actingAs($this->helpdesk)->post("/pm-visits/{$visit->ulid}/move", ['action' => 'schedule'])->assertSessionHasNoErrors();
    expect($visit->fresh()->status)->toBe(PmVisit::STATUS_SCHEDULED);

    // Started, it checks what was listed on it (no contract to take equipment from).
    $this->actingAs($this->tech)->post("/pm-visits/{$visit->ulid}/start")->assertSessionHasNoErrors();
    expect($visit->items()->count())->toBe(1)
        ->and(PmVisitEvent::where('pm_visit_id', $visit->id)->orderBy('id')->pluck('type')->all())->toBe(['created', 'item_added', 'schedule', 'start']);
});

it('takes only equipment and contracts of the work order\'s own customer', function () {
    ($this->create)(['asset_ids' => [$this->upsA->id, $this->theirs->id]])->assertSessionHasErrors('asset_ids');
    ($this->create)(['contract_id' => createContract(createCustomer())->id])->assertSessionHasErrors('contract_id');
    ($this->create)(['customer_id' => 999999])->assertSessionHasErrors('customer_id');
    expect(PmVisit::count())->toBe(0);

    $contract = createContract($this->customer);
    ($this->create)(['contract_id' => $contract->id])->assertSessionHasNoErrors();
    expect(PmVisit::sole()->contract_id)->toBe($contract->id);
});

it('lets only who may create work orders make one', function () {
    $this->actingAs($this->tech)->get('/pm-visits/create')->assertForbidden();
    $this->actingAs($this->tech)->post('/pm-visits', ['customer_id' => $this->customer->id])->assertForbidden();
    $this->actingAs(userWithRole('customer_it', ['customer_id' => $this->customer->id]))->get('/pm-visits/create')->assertForbidden();

    $this->actingAs($this->helpdesk)->get('/pm-visits/create?customer_id='.$this->customer->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Visits/Create')
            ->where('assets', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['UPS A', 'UPS B']));
    $this->actingAs($this->helpdesk)->get('/pm-visits')->assertInertia(fn (Assert $page) => $page->where('can.create', true));
});

it('adds and removes equipment while the work is still to do, never recorded work', function () {
    ($this->create)();
    $visit = PmVisit::sole();
    $url = "/pm-visits/{$visit->ulid}";

    $this->actingAs($this->helpdesk)->get("{$url}?asset_search=UPS")->assertInertia(fn (Assert $page) => $page
        ->where('can.editItems', true)
        ->where('candidates', fn ($rows) => collect($rows)->pluck('name')->all() === ['UPS B']));
    $this->actingAs($this->helpdesk)->post("{$url}/items", ['asset_ids' => [$this->theirs->id]])->assertSessionHasErrors('asset_ids');
    $this->actingAs($this->helpdesk)->post("{$url}/items", ['asset_ids' => [$this->upsB->id, $this->upsA->id]])->assertSessionHasNoErrors();
    expect($visit->items()->count())->toBe(2);

    // Recorded equipment stays; untouched equipment may go.
    $this->actingAs($this->tech)->post("{$url}/start");
    $a = $visit->items()->where('asset_id', $this->upsA->id)->first();
    $b = $visit->items()->where('asset_id', $this->upsB->id)->first();
    $this->actingAs($this->tech)->put("{$url}/items/{$a->id}", ['result' => 'ok', 'answers' => ['battery' => 'pass', 'volt' => 220]])->assertSessionHasNoErrors();
    $this->actingAs($this->helpdesk)->delete("{$url}/items/{$a->id}")->assertSessionHasErrors('item');
    $this->actingAs($this->helpdesk)->delete("{$url}/items/{$b->id}")->assertSessionHasNoErrors();
    expect($visit->items()->pluck('asset_id')->all())->toBe([$this->upsA->id]);

    // Another technician (pm-visits.update own) may not change the equipment of a work order not theirs.
    $other = userWithRole('technician');
    $this->actingAs($other)->post("{$url}/items", ['asset_ids' => [$this->upsB->id]])->assertForbidden();

    // Handed in: the list of equipment is fixed.
    $this->actingAs($this->tech)->post("{$url}/submit", ['technician_signature' => signaturePng()])->assertSessionHasNoErrors();
    $this->actingAs($this->helpdesk)->post("{$url}/items", ['asset_ids' => [$this->upsB->id]])->assertSessionHasErrors('asset_ids');
});

it('records Pass / Fail / N/A, readings against their range, a note per question and photos of a question', function () {
    ($this->create)();
    $visit = PmVisit::sole();
    $url = "/pm-visits/{$visit->ulid}";
    $this->actingAs($this->tech)->post("{$url}/start");
    $item = $visit->items()->first();
    $record = fn (array $data) => $this->actingAs($this->tech)->put("{$url}/items/{$item->id}", $data);

    $record(['result' => 'ok', 'answers' => ['battery' => 'maybe']])->assertSessionHasErrors('answers.battery');
    // The required question must be answered (unless the equipment is skipped).
    $record(['result' => 'ok', 'answers' => ['volt' => 220]])->assertSessionHasErrors('answers');
    $record(['result' => 'skipped', 'note' => 'ปิดเครื่องอยู่'])->assertSessionHasNoErrors();
    // A reading out of range is a fail: the equipment cannot be fine.
    $record(['result' => 'ok', 'answers' => ['battery' => 'pass', 'volt' => 250]])->assertSessionHasErrors('result');
    $record(['result' => 'ok', 'answers' => ['battery' => 'fail', 'volt' => 220]])->assertSessionHasErrors('result');
    $record([
        'result' => 'issue', 'answers' => ['battery' => 'fail', 'volt' => '250', 'look' => '  บวม  ', 'extra' => 'x'],
        'remarks' => ['battery' => 'แบตบวม 2 ก้อน', 'unknown' => 'x', 'volt' => ''], 'note' => 'ต้องเปลี่ยนแบต',
    ])->assertSessionHasNoErrors();

    $item->refresh();
    expect($item->answers)->toEqual(['battery' => 'fail', 'volt' => 250.0, 'look' => 'บวม'])
        ->and($item->remarks)->toBe(['battery' => 'แบตบวม 2 ก้อน']);

    // A photo of one question, and one of the whole equipment.
    $this->actingAs($this->tech)->post("{$url}/items/{$item->id}/photos", [
        'photo' => UploadedFile::fake()->image('battery.jpg'), 'question' => 'battery', 'caption' => 'แบตบวม',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$url}/items/{$item->id}/photos", ['photo' => UploadedFile::fake()->image('ups.jpg')])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("{$url}/items/{$item->id}/photos", [
        'photo' => UploadedFile::fake()->image('x.jpg'), 'question' => 'nope',
    ])->assertSessionHasErrors('photo');

    $photos = $item->getMedia(PmVisitItem::PHOTOS);
    expect($photos->map(fn ($m) => $m->getCustomProperty('question'))->all())->toBe(['battery', null])
        ->and($photos[0]->getCustomProperty('caption'))->toBe('แบตบวม');
    $this->actingAs($this->tech)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('items.0.remarks.battery', 'แบตบวม 2 ก้อน')
        ->where('items.0.photos.0.question', 'battery'));
});

it('saves checklist templates with Pass/Fail, readings with unit and range, sections and required questions', function () {
    $admin = userWithRole('admin_company');
    $this->actingAs($admin)->post('/pm-checklists', [
        'name' => 'Generator PM', 'items' => [
            ['key' => 'oil', 'label' => 'ระดับน้ำมัน', 'type' => 'pass_fail', 'section' => 'เครื่องยนต์', 'required' => true],
            ['key' => 'volt', 'label' => 'แรงดัน', 'type' => 'measure', 'unit' => 'V', 'min' => 240, 'max' => 210],
        ],
    ])->assertSessionHasErrors('items.1.max');

    $this->actingAs($admin)->post('/pm-checklists', [
        'name' => 'Generator PM', 'items' => [
            ['key' => 'oil', 'label' => 'ระดับน้ำมัน', 'type' => 'pass_fail', 'section' => 'เครื่องยนต์', 'required' => true, 'unit' => 'x'],
            ['key' => 'volt', 'label' => 'แรงดัน', 'type' => 'measure', 'unit' => 'V', 'min' => '210', 'max' => '240', 'required' => false],
        ],
    ])->assertSessionHasNoErrors();

    expect(PmChecklist::where('name', 'Generator PM')->sole()->items)->toEqual([
        ['key' => 'oil', 'label' => 'ระดับน้ำมัน', 'type' => 'pass_fail', 'section' => 'เครื่องยนต์', 'required' => true],
        ['key' => 'volt', 'label' => 'แรงดัน', 'type' => 'measure', 'unit' => 'V', 'min' => 210.0, 'max' => 240.0],
    ]);
});
