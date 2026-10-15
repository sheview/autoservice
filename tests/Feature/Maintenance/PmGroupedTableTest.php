<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-02-10 09:00');
    $this->admin = userWithRole('admin_company');
    $this->tech = userWithRole('technician');
    $this->otherTech = userWithRole('technician');

    $this->pcs = createAssetCategory(['name' => 'PC', 'code_prefix' => 'PC']);
    $this->monitors = createAssetCategory(['name' => 'Monitor', 'code_prefix' => 'MON']);
    createPmTemplate(['code' => 'PC-SET', 'asset_category_id' => $this->pcs->id, 'layout' => PmChecklist::LAYOUT_GROUPED_TABLE, 'items' => [
        ['key' => 'serial', 'label' => 'Serial', 'type' => 'readonly_from_asset', 'source' => 'serial_number'],
        ['key' => 'cleaning', 'label' => 'ทำความสะอาด', 'type' => 'pass_fail', 'required' => true],
        ['key' => 'detail', 'label' => 'รายละเอียด', 'type' => 'text'],
    ]]);
    createPmTemplate(['code' => 'MON-SET', 'asset_category_id' => $this->monitors->id, 'layout' => PmChecklist::LAYOUT_GROUPED_TABLE, 'items' => [
        ['key' => 'screen', 'label' => 'หน้าจอ', 'type' => 'pass_fail'],
        ['key' => 'size', 'label' => 'ขนาด (นิ้ว)', 'type' => 'number', 'required' => true],
    ]]);

    $this->customer = createCustomer(['name' => 'Acme']);
    $this->pc1 = createAsset($this->pcs, ['customer_id' => $this->customer->id, 'name' => 'PC บัญชี', 'serial_number' => 'SN-PC1']);
    $this->pc2 = createAsset($this->pcs, ['customer_id' => $this->customer->id, 'name' => 'PC การเงิน']);
    $this->monitor = createAsset($this->monitors, ['customer_id' => $this->customer->id, 'name' => 'จอ บัญชี', 'parent_asset_id' => $this->pc1->id]);
    // a part of another customer's (wrongly tied) is not checked here
    createAsset($this->monitors, ['customer_id' => createCustomer()->id, 'name' => 'จอ ของคนอื่น', 'parent_asset_id' => $this->pc2->id]);

    $contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->createMany([['asset_id' => $this->pc1->id], ['asset_id' => $this->pc2->id]]);
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM PC', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $this->visit = app(StartPmVisit::class)->handle($plan->visits()->first(), $this->tech);
    $this->item = fn ($asset) => $this->visit->items()->where('asset_id', $asset->id)->first();
    $this->rowUrl = fn (PmVisitItem $item) => "/pm-work/{$this->visit->ulid}/rows/{$item->id}";
});

it('checks a PC with its monitor as a set: a row each, the monitor with its own template', function () {
    expect($this->visit->items()->get()->pluck('template_code', 'asset_id')->all())->toEqual([
        $this->pc1->id => 'PC-SET', $this->pc2->id => 'PC-SET', $this->monitor->id => 'MON-SET',
    ]);

    $this->actingAs($this->tech)->get("/pm-work/{$this->visit->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Field/Visit')
        ->where('items', fn ($items) => collect($items)->every(fn ($item) => $item['layout'] === PmChecklist::LAYOUT_GROUPED_TABLE)
            && collect($items)->firstWhere('asset.id', $this->monitor->id)['asset']['parent_asset_id'] === $this->pc1->id
            && collect($items)->firstWhere('asset.id', $this->pc1->id)['asset']['ulid'] === $this->pc1->ulid)
        ->where('tables', fn ($tables) => collect($tables)->keys()->sort()->values()->all() === ['MON-SET', 'PC-SET']
            && collect($tables['PC-SET'])->pluck('key')->all() === ['serial', 'cleaning', 'detail'])
        ->where('notInspectedReasons', PmVisitItem::NOT_INSPECTED_REASONS));
});

it('saves a row as it is filled in, by the same rules as the full form', function () {
    $pc1 = ($this->item)($this->pc1);
    $instrument = createPmInstrument();
    $pc1->update(['instrument_id' => $instrument->id, 'remarks' => ['cleaning' => 'ฝุ่นเยอะ']]);

    $this->actingAs($this->tech)->putJson(($this->rowUrl)($pc1), ['result' => 'passed', 'answers' => ['cleaning' => 'pass', 'detail' => 'เป่าฝุ่น']])
        ->assertOk()->assertJsonPath('item.result', 'passed')->assertJsonPath('item.answers.serial', 'SN-PC1');
    // what the row does not show stays
    expect($pc1->fresh()->only(['instrument_id', 'remarks', 'checked_by']))->toBe(['instrument_id' => $instrument->id, 'remarks' => ['cleaning' => 'ฝุ่นเยอะ'], 'checked_by' => $this->tech->id]);

    $this->actingAs($this->tech)->putJson(($this->rowUrl)($pc1), ['result' => 'failed', 'answers' => ['cleaning' => 'fail']])->assertJsonValidationErrors('note');
    $this->actingAs($this->tech)->putJson(($this->rowUrl)($pc1), ['result' => 'passed', 'answers' => ['cleaning' => 'fail']])->assertJsonValidationErrors('result');
    $this->actingAs($this->tech)->putJson(($this->rowUrl)($pc1), ['result' => 'not_inspected'])->assertJsonValidationErrors('not_inspected_reason');
    $this->actingAs($this->tech)->putJson(($this->rowUrl)($pc1), ['result' => 'not_inspected', 'not_inspected_reason' => 'user_absent'])
        ->assertOk()->assertJsonPath('item.not_inspected_reason', 'user_absent');

    // only who does the work, while it goes on, on this work order
    $this->actingAs($this->otherTech)->putJson(($this->rowUrl)($pc1), ['result' => 'passed', 'answers' => ['cleaning' => 'pass']])->assertForbidden();
    $this->visit->update(['status' => PmVisit::STATUS_SUBMITTED]);
    $this->actingAs($this->tech)->putJson(($this->rowUrl)($pc1), ['result' => 'passed', 'answers' => ['cleaning' => 'pass']])->assertJsonValidationErrors('visit');
});

it('passes every row without a result at once, or one set, skipping what is not complete', function () {
    $pc1 = ($this->item)($this->pc1);
    $pc2 = ($this->item)($this->pc2);
    $monitor = ($this->item)($this->monitor);
    // PC 2 was recorded as failed by hand: it stays failed
    $this->actingAs($this->tech)->putJson(($this->rowUrl)($pc2), ['result' => 'failed', 'answers' => ['cleaning' => 'fail'], 'note' => 'พัดลมเสีย'])->assertOk();

    // the set of PC 1: the monitor misses its size (required), so it waits
    $this->actingAs($this->tech)->postJson("/pm-work/{$this->visit->ulid}/pass-all", ['item_ids' => [$pc1->id, $monitor->id]])
        ->assertOk()->assertJsonPath('skipped', [$monitor->id])->assertJsonPath('items.0.id', $pc1->id);
    expect($pc1->fresh()->only(['result', 'answers']))->toBe(['result' => 'passed', 'answers' => ['serial' => 'SN-PC1', 'cleaning' => 'pass', 'detail' => null]])
        ->and($pc2->fresh()->result)->toBe('failed')
        ->and($monitor->fresh()->result)->toBe('pending');

    // the size typed (autosaved as a draft) is used
    $monitor->update(['draft' => ['answers' => ['size' => 24]]]);
    $this->actingAs($this->tech)->postJson("/pm-work/{$this->visit->ulid}/pass-all", ['item_ids' => [$pc1->id, $pc2->id, $monitor->id]])
        ->assertOk()->assertJsonPath('skipped', []);
    expect($monitor->fresh()->only(['result', 'answers', 'draft']))->toEqual(['result' => 'passed', 'answers' => ['screen' => 'pass', 'size' => 24], 'draft' => null]);

    $this->actingAs($this->otherTech)->postJson("/pm-work/{$this->visit->ulid}/pass-all", ['item_ids' => [$pc1->id]])->assertForbidden();
});

it('keeps rows of a work order to its own company', function () {
    $other = createTenant('other');
    $theirs = asTenant($other, function () {
        $tech = userWithRole('technician');
        $customer = createCustomer();
        $pc = createAsset(createAssetCategory(), ['customer_id' => $customer->id]);
        $contract = createContract($customer);
        $contract->contractAssets()->create(['asset_id' => $pc->id]);
        $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'Theirs', 'interval_months' => 6, 'assignee_id' => $tech->id]);

        return app(StartPmVisit::class)->handle($plan->visits()->first(), $tech)->items()->first();
    });

    $this->actingAs($this->admin)->putJson("/pm-work/{$this->visit->ulid}/rows/{$theirs->id}", ['result' => 'passed'])->assertNotFound();
    $this->actingAs($this->admin)->postJson("/pm-work/{$this->visit->ulid}/pass-all", ['item_ids' => [$theirs->id]])
        ->assertOk()->assertJsonPath('items', []);
    expect(asTenant($other, fn () => $theirs->fresh()->result))->toBe('pending');
});

it('prints a part under its main piece, when both are in the same table', function () {
    $pcTemplate = PmChecklist::where('code', 'PC-SET')->first();
    ($this->item)($this->monitor)->update(['pm_checklist_id' => $pcTemplate->id, 'template_code' => 'PC-SET', 'checklist' => $pcTemplate->items]);
    foreach ([$this->pc1, $this->pc2, $this->monitor] as $asset) {
        ($this->item)($asset)->update(['result' => 'passed']);
    }

    $html = $this->actingAs($this->admin)->get("/pm-visits/{$this->visit->ulid}/sheet")->assertOk()->getContent();
    $html = substr($html, strpos($html, 'class="grouped"'));
    expect(strpos($html, $this->pc1->asset_code))->toBeLessThan(strpos($html, '↳'))
        ->and(strpos($html, '↳'))->toBeLessThan(strpos($html, $this->monitor->asset_code));
});

it('keeps a work order of 300 pieces of equipment quick to open and to pass', function () {
    // 150 PCs, each with a monitor
    $customer = createCustomer();
    $pcIds = seedPmAssets($this->pcs, 150, 'LPC', ['customer_id' => $customer->id, 'floor' => '3']);
    $monitorIds = seedPmAssets($this->monitors, 150, 'LMON', ['customer_id' => $customer->id, 'floor' => '3']);
    foreach ($monitorIds as $i => $monitorId) {
        DB::table('assets')->where('tenant_id', $this->tenant->id)->where('id', $monitorId)->update(['parent_asset_id' => $pcIds[$i]]);
    }
    $contract = createContract($customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $contract->contractAssets()->createMany(array_map(fn ($id) => ['asset_id' => $id], $pcIds));
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM 300', 'interval_months' => 6, 'assignee_id' => $this->tech->id]);
    $visit = app(StartPmVisit::class)->handle($plan->visits()->first(), $this->tech);
    expect($visit->items()->count())->toBe(300);

    // the page: a fixed number of queries, whatever the number of rows, and a reasonable size
    DB::enableQueryLog();
    $started = microtime(true);
    $response = $this->actingAs($this->tech)->get("/pm-work/{$visit->ulid}")->assertOk();
    $seconds = microtime(true) - $started;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    preg_match('/data-page="([^"]+)"/', $response->getContent(), $page);
    expect($queries)->toBeLessThan(40)
        ->and($seconds)->toBeLessThan(3)
        // the rows (JSON, before compression; the translations every page carries are left out)
        ->and(strlen(json_encode(json_decode(html_entity_decode($page[1]), true)['props']['items'])))->toBeLessThan(120_000);

    // ผ่านทั้งหมด on the PCs (the monitors wait for their size)
    $started = microtime(true);
    $this->actingAs($this->tech)->postJson("/pm-work/{$visit->ulid}/pass-all", ['item_ids' => $visit->items()->pluck('id')->all()])
        ->assertOk()->assertJsonCount(150, 'skipped');
    expect(microtime(true) - $started)->toBeLessThan(15)
        ->and($visit->items()->where('result', 'passed')->count())->toBe(150);
});
