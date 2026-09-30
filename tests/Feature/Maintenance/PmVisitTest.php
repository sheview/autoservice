<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Service\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-02-10 10:00');

    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);

    $this->switches = createAssetCategory(['name' => 'Switch']);
    $this->printers = createAssetCategory(['name' => 'Printer']);
    $this->switch = createAsset($this->switches, ['customer_id' => $this->customer->id, 'name' => 'Core switch']);
    $this->printer = createAsset($this->printers, ['customer_id' => $this->customer->id, 'name' => 'Printer']);

    $this->contract = createContract($this->customer, [
        'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'slas' => ['medium' => ['response_minutes' => 240, 'resolve_minutes' => 480]],
    ]);
    $this->contract->contractAssets()->create(['asset_id' => $this->switch->id]);
    $this->contract->contractAssets()->create(['asset_id' => $this->printer->id]);

    PmChecklist::create(['name' => 'Switch PM', 'asset_category_id' => $this->switches->id, 'items' => [
        ['key' => 'clean', 'label' => 'ทำความสะอาด', 'type' => 'check'],
        ['key' => 'temp', 'label' => 'อุณหภูมิ', 'type' => 'number'],
    ]]);
    PmChecklist::create(['name' => 'General', 'items' => [['key' => 'look', 'label' => 'ตรวจสภาพ', 'type' => 'text']]]);

    $this->plan = app(SavePmPlan::class)->handle(null, [
        'contract_id' => $this->contract->id, 'title' => 'PM Acme', 'interval_months' => 3, 'assignee_id' => $this->tech->id,
    ]);
    $this->visit = $this->plan->visits()->orderBy('round')->first();
});

function itemOf(PmVisit $visit, int $assetId): PmVisitItem
{
    return $visit->items()->where('asset_id', $assetId)->first();
}

it('starts a round with one item per contract asset and the checklist of its category', function () {
    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/start")->assertSessionHasNoErrors();

    $visit = $this->visit->fresh();
    expect($visit->status)->toBe(PmVisit::STATUS_IN_PROGRESS)
        ->and($visit->started_at)->not->toBeNull()
        ->and(itemOf($visit, $this->switch->id)->checklist)->toHaveCount(2)
        ->and(itemOf($visit, $this->printer->id)->checklist)->toEqual([['key' => 'look', 'label' => 'ตรวจสภาพ', 'type' => 'text']]);

    // later checklist edits do not change a started round
    PmChecklist::where('name', 'General')->first()->update(['items' => []]);
    expect(itemOf($visit, $this->printer->id)->checklist)->toHaveCount(1);

    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/start")->assertSessionHasErrors('visit');
});

it('records results per asset and closes the round once every asset has one', function () {
    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/start");
    $switchItem = itemOf($this->visit, $this->switch->id);
    $printerItem = itemOf($this->visit, $this->printer->id);

    $this->actingAs($this->tech)->put("/pm-visits/{$this->visit->ulid}/items/{$switchItem->id}", [
        'result' => 'ok', 'answers' => ['clean' => true, 'temp' => 'hot', 'extra' => 'x'],
    ])->assertSessionHasErrors('answers.temp');

    $this->actingAs($this->tech)->put("/pm-visits/{$this->visit->ulid}/items/{$switchItem->id}", [
        'result' => 'ok', 'answers' => ['clean' => true, 'temp' => '38.5', 'extra' => 'x'],
    ])->assertSessionHasNoErrors();
    expect($switchItem->fresh()->answers)->toEqual(['clean' => true, 'temp' => 38.5])
        ->and($switchItem->fresh()->checked_by)->toBe($this->tech->id);

    // an issue needs a note
    $this->actingAs($this->tech)->put("/pm-visits/{$this->visit->ulid}/items/{$printerItem->id}", ['result' => 'issue'])
        ->assertSessionHasErrors('note');

    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/complete")->assertSessionHasErrors('visit');

    $this->actingAs($this->tech)->put("/pm-visits/{$this->visit->ulid}/items/{$printerItem->id}", [
        'result' => 'issue', 'answers' => ['look' => 'ลูกยางดึงกระดาษสึก'], 'note' => 'ดึงกระดาษไม่เข้า',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/complete", ['summary' => 'เรียบร้อย'])->assertSessionHasNoErrors();
    expect($this->visit->fresh()->status)->toBe(PmVisit::STATUS_COMPLETED)
        ->and($this->visit->fresh()->summary)->toBe('เรียบร้อย');

    // a closed round is read only
    $this->actingAs($this->tech)->put("/pm-visits/{$this->visit->ulid}/items/{$printerItem->id}", ['result' => 'ok'])
        ->assertSessionHasErrors('visit');

    // the asset page shows its PM history
    $this->actingAs($this->tech)->get("/assets/{$this->printer->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('pmHistory.0.visit_no', $this->visit->visit_no)->where('pmHistory.0.result', 'issue'));
});

it('opens a repair ticket for an asset found with an issue', function () {
    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/start");
    $item = itemOf($this->visit, $this->printer->id);

    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/items/{$item->id}/ticket", ['priority' => 'medium'])
        ->assertSessionHasErrors('item');

    $this->actingAs($this->tech)->put("/pm-visits/{$this->visit->ulid}/items/{$item->id}", ['result' => 'issue', 'note' => 'ดึงกระดาษไม่เข้า']);
    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/items/{$item->id}/ticket", ['priority' => 'medium'])
        ->assertSessionHasNoErrors();

    $ticket = Ticket::first();
    expect($ticket->source)->toBe('pm')
        ->and($ticket->asset_id)->toBe($this->printer->id)
        ->and($ticket->customer_id)->toBe($this->customer->id)
        ->and($ticket->contract_id)->toBe($this->contract->id)
        ->and($ticket->resolve_minutes)->toBe(480)
        ->and($ticket->description)->toBe('ดึงกระดาษไม่เข้า')
        ->and($ticket->title)->toContain($this->visit->visit_no)
        ->and($item->fresh()->ticket_id)->toBe($ticket->id);

    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/items/{$item->id}/ticket", ['priority' => 'medium'])
        ->assertSessionHasErrors('item');
    expect(Ticket::count())->toBe(1);

    $this->actingAs($this->tech)->get("/pm-visits/{$this->visit->ulid}")
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Visits/Show')
            ->where('items.1.ticket.ticket_no', $ticket->ticket_no));
});

it('lets only the assignee or a PM manager work on a round', function () {
    $otherTech = userWithRole('technician');

    $this->actingAs($otherTech)->get("/pm-visits/{$this->visit->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('can.start', false)->where('can.update', false));
    $this->actingAs($otherTech)->post("/pm-visits/{$this->visit->ulid}/start")->assertForbidden();
    $this->actingAs($otherTech)->post("/pm-visits/{$this->visit->ulid}/cancel", ['reason' => 'x'])->assertForbidden();

    // helpdesk re-assigns and schedules; it does not perform PM
    $this->actingAs($this->helpdesk)->put("/pm-visits/{$this->visit->ulid}", ['scheduled_on' => '2026-03-20', 'assignee_id' => $otherTech->id])
        ->assertSessionHasNoErrors();
    expect($this->visit->fresh()->scheduled_on->toDateString())->toBe('2026-03-20');
    $this->actingAs($this->helpdesk)->post("/pm-visits/{$this->visit->ulid}/start")->assertForbidden();

    $this->actingAs($otherTech)->post("/pm-visits/{$this->visit->ulid}/start")->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("/pm-visits/{$this->visit->ulid}/complete")->assertForbidden();
});

it('cancels a round with a reason', function () {
    $this->actingAs($this->helpdesk)->post("/pm-visits/{$this->visit->ulid}/cancel")->assertSessionHasErrors('reason');
    $this->actingAs($this->helpdesk)->post("/pm-visits/{$this->visit->ulid}/cancel", ['reason' => 'ลูกค้าปิดปรับปรุงสำนักงาน'])
        ->assertSessionHasNoErrors();

    expect($this->visit->fresh()->status)->toBe(PmVisit::STATUS_CANCELLED)
        ->and($this->visit->fresh()->summary)->toBe('ลูกค้าปิดปรับปรุงสำนักงาน');

    $this->actingAs($this->helpdesk)->put("/pm-visits/{$this->visit->ulid}", ['scheduled_on' => '2026-03-01'])->assertSessionHasErrors('visit');
});

it('lists rounds with search, filters and sort', function () {
    $this->travelTo('2026-04-05');
    $visits = $this->plan->visits()->orderBy('round')->get();
    $visits[3]->update(['assignee_id' => null]);

    $list = fn (string $query) => $this->actingAs($this->tech)->get("/pm-visits{$query}");

    // open by default, soonest due first; round 1 (due 2026-03-31) is overdue
    $list('')->assertInertia(fn (Assert $page) => $page->component('Maintenance/Visits/Index')
        ->where('visits.total', 4)
        ->where('visits.data.0.visit_no', $visits[0]->visit_no)
        ->where('visits.data.0.overdue', true)
        ->where('visits.data.1.overdue', false));
    $list('?status=overdue')->assertInertia(fn (Assert $page) => $page->where('visits.total', 1));
    $list('?assignee=me')->assertInertia(fn (Assert $page) => $page->where('visits.total', 3));
    $list('?assignee=none')->assertInertia(fn (Assert $page) => $page->where('visits.total', 1));
    $list('?month=2026-06')->assertInertia(fn (Assert $page) => $page->where('visits.total', 1)->where('visits.data.0.visit_no', $visits[1]->visit_no));
    $list('?search='.$visits[2]->visit_no)->assertInertia(fn (Assert $page) => $page->where('visits.total', 1));
    $list('?search=acme')->assertInertia(fn (Assert $page) => $page->where('visits.total', 4));
    $list('?sort=due_on&direction=desc')->assertInertia(fn (Assert $page) => $page->where('visits.data.0.visit_no', $visits[3]->visit_no));
    $list('?customer_id='.createCustomer()->id)->assertInertia(fn (Assert $page) => $page->where('visits.total', 0));
});
