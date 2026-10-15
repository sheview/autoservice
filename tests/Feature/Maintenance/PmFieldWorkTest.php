<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Platform\Support\Modules;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo('2026-06-10 09:00');
    $this->tech = userWithRole('technician', ['name' => 'ช่างหนึ่ง']);
    $this->otherTech = userWithRole('technician', ['name' => 'ช่างสอง']);

    $category = createAssetCategory(['name' => 'UPS', 'code_prefix' => 'UPS']);
    createPmTemplate(['code' => 'UPS', 'asset_category_id' => $category->id, 'items' => [
        ['key' => 'fan', 'label' => 'Fan', 'type' => 'pass_fail', 'required' => true],
        ['key' => 'look', 'label' => 'Look', 'type' => 'pass_fail'],
        ['key' => 'volt', 'label' => 'Voltage', 'type' => 'number', 'unit' => 'V', 'min' => 210, 'max' => 240],
    ]]);
    $customer = createCustomer(['name' => 'ลูกค้า ก']);
    $contract = createContract($customer);
    $this->upsA = createAsset($category, ['customer_id' => $customer->id, 'name' => 'UPS A', 'floor' => '2', 'room' => 'Server']);
    $this->upsB = createAsset($category, ['customer_id' => $customer->id, 'name' => 'UPS B', 'location' => 'อาคาร B']);
    $contract->contractAssets()->createMany([['asset_id' => $this->upsA->id], ['asset_id' => $this->upsB->id]]);

    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM UPS', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $this->visit = $plan->visits()->orderBy('round')->first();
    $this->url = "/pm-work/{$this->visit->ulid}";
});

it('lists the technician\'s own PM work orders in my work, with how far along', function () {
    $theirs = PmVisit::where('id', '!=', $this->visit->id)->first();
    $theirs->update(['assignee_id' => $this->otherTech->id]);

    $this->actingAs($this->tech)->get('/my-work')->assertInertia(fn (Assert $page) => $page
        ->where('todo', fn ($todo) => collect($todo)->where('kind', 'pm')->pluck('key')->all() === ["pm-{$this->visit->id}"]
            && collect($todo)->firstWhere('kind', 'pm')['progress'] === ['done' => 0, 'total' => 0]
            && str_contains(collect($todo)->firstWhere('kind', 'pm')['title'], 'ลูกค้า ก')
            && collect($todo)->firstWhere('kind', 'pm')['href'] === route('maintenance.field.visit', $this->visit)));

    // the module switched off: no PM in my work
    Feature::for($this->tech->tenant)->deactivate(Modules::feature('maintenance'));
    $this->actingAs($this->tech)->get('/my-work')->assertInertia(fn (Assert $page) => $page
        ->where('todo', fn ($todo) => collect($todo)->where('kind', 'pm')->isEmpty()));
    $this->actingAs($this->tech)->get($this->url)->assertNotFound();
});

it('opens a work order on the phone only for who does the work', function () {
    $this->actingAs($this->otherTech)->get($this->url)->assertForbidden();
    $this->actingAs($this->otherTech)->post("{$this->url}/arrive")->assertForbidden();

    $outsider = userWithRole('technician', tenant: createTenant('other'));
    $this->actingAs($outsider)->get($this->url)->assertNotFound();

    $this->actingAs($this->tech)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Field/Visit')
        ->where('visit.can_start', true)
        ->where('visit.customer', 'ลูกค้า ก')
        ->where('items', []));
});

it('starts the work on arrival once, and leaves only after arriving', function () {
    $this->actingAs($this->tech)->post("{$this->url}/depart")->assertSessionHasErrors('visit');

    $this->actingAs($this->tech)->post("{$this->url}/arrive")->assertSessionHasNoErrors();
    $visit = $this->visit->fresh();
    expect($visit->status)->toBe(PmVisit::STATUS_IN_PROGRESS)
        ->and($visit->onsite_at->format('H:i'))->toBe('09:00')
        ->and($visit->items()->count())->toBe(2);

    $this->travel(30)->minutes();
    $this->actingAs($this->tech)->post("{$this->url}/arrive")->assertSessionHasNoErrors();
    expect($visit->fresh()->onsite_at->equalTo($visit->onsite_at))->toBeTrue();

    $this->actingAs($this->tech)->post("{$this->url}/depart")->assertSessionHasNoErrors();
    expect($visit->fresh()->departed_at)->not->toBeNull()
        ->and(PmVisitEvent::where('pm_visit_id', $visit->id)->whereIn('type', ['arrived', 'departed'])->pluck('type')->all())->toBe(['arrived', 'departed']);

    // the equipment by place, and one piece's form with its neighbours
    $this->actingAs($this->tech)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('items.0.asset.floor', '2')->where('items.0.asset.room', 'Server')->where('items.1.asset.location', 'อาคาร B'));
    $first = $visit->items()->orderBy('id')->first();
    $this->actingAs($this->tech)->get("{$this->url}/items/{$first->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Field/Item')
        ->where('position', ['index' => 1, 'total' => 2])
        ->where('previous', null)
        ->where('next', $visit->items()->orderBy('id')->skip(1)->first()->id));
});

it('autosaves a draft without touching the recorded result, and saving clears it', function () {
    $this->actingAs($this->tech)->post("{$this->url}/arrive");
    $item = $this->visit->items()->orderBy('id')->first();
    $instrument = createPmInstrument(['name' => 'Fluke 117']);
    $draftUrl = "{$this->url}/items/{$item->id}/draft";
    $saveUrl = "/pm-visits/{$this->visit->ulid}/items/{$item->id}";

    // values must still be of their type, but nothing is required yet
    $this->actingAs($this->tech)->putJson($draftUrl, ['answers' => ['volt' => 'abc']])->assertUnprocessable();
    $this->actingAs($this->tech)->putJson($draftUrl, ['answers' => ['volt' => 250, 'fan' => 'pass'], 'instrument_id' => $instrument->id])
        ->assertOk()->assertJsonStructure(['saved_at']);
    $item->refresh();
    expect($item->result)->toBe(PmVisitItem::RESULT_PENDING)
        ->and($item->draft['answers'])->toEqual(['volt' => 250, 'fan' => 'pass'])
        ->and($item->draft['instrument_id'])->toBe($instrument->id);
    $this->actingAs($this->otherTech)->putJson($draftUrl, ['answers' => []])->assertForbidden();

    // the item page starts from the draft
    $this->actingAs($this->tech)->get("{$this->url}/items/{$item->id}")->assertInertia(fn (Assert $page) => $page
        ->where('item.draft.answers.volt', 250)->where('instruments.0.name', 'Fluke 117'));

    // saving for good: the reading out of range is a fail, then the draft is gone
    $this->actingAs($this->tech)->put($saveUrl, ['result' => 'passed', 'answers' => ['fan' => 'pass', 'volt' => 250]])->assertSessionHasErrors('result');
    $this->actingAs($this->tech)->put($saveUrl, ['result' => 'failed', 'note' => 'แรงดันสูง', 'answers' => ['fan' => 'pass', 'volt' => 250], 'instrument_id' => $instrument->id])
        ->assertSessionHasNoErrors();
    $item->refresh();
    expect($item->result)->toBe(PmVisitItem::RESULT_FAILED)->and($item->draft)->toBeNull()->and($item->instrument_id)->toBe($instrument->id);

    // a draft afterwards keeps the recorded result; saving again changes the same row
    $this->actingAs($this->tech)->putJson($draftUrl, ['answers' => ['volt' => 220]])->assertOk();
    expect($item->fresh()->result)->toBe(PmVisitItem::RESULT_FAILED);
    $this->actingAs($this->tech)->put($saveUrl, ['result' => 'passed', 'answers' => ['fan' => 'pass', 'volt' => 220]])->assertSessionHasNoErrors();
    expect(PmVisitItem::where('pm_visit_id', $this->visit->id)->where('asset_id', $item->asset_id)->count())->toBe(1)
        ->and($item->fresh()->result)->toBe(PmVisitItem::RESULT_PASSED);
});

it('asks why equipment was not inspected, and takes only the company\'s own instruments', function () {
    $this->actingAs($this->tech)->post("{$this->url}/arrive");
    $item = $this->visit->items()->orderBy('id')->first();
    $saveUrl = "/pm-visits/{$this->visit->ulid}/items/{$item->id}";

    $this->actingAs($this->tech)->put($saveUrl, ['result' => 'not_inspected'])->assertSessionHasErrors('not_inspected_reason');
    $this->actingAs($this->tech)->put($saveUrl, ['result' => 'not_inspected', 'not_inspected_reason' => 'user_absent', 'note' => 'ห้องล็อก'])
        ->assertSessionHasNoErrors();
    expect($item->fresh()->not_inspected_reason)->toBe('user_absent');

    $theirs = asTenant(createTenant('other'), fn () => createPmInstrument());
    $this->actingAs($this->tech)->put($saveUrl, ['result' => 'not_inspected', 'not_inspected_reason' => 'not_found', 'instrument_id' => $theirs->id])
        ->assertSessionHasErrors('instrument_id');
});
