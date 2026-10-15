<?php

use App\Modules\Maintenance\Actions\GeneratePmRounds;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\SchedulePmVisits;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Platform\Support\Modules;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo('2026-01-15 06:00');

    $this->admin = userWithRole('admin_company');
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician');
    $this->customer = createCustomer(['name' => 'Acme']);
    $category = createAssetCategory(['name' => 'UPS']);
    $this->upsA = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'UPS Alpha']);
    $this->upsB = createAsset($category, ['customer_id' => $this->customer->id, 'name' => 'UPS Bravo']);
    $this->contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $this->contract->contractAssets()->createMany([['asset_id' => $this->upsA->id], ['asset_id' => $this->upsB->id]]);
    createPmTemplate(['name' => 'General', 'items' => [['key' => 'look', 'label' => 'Look', 'type' => 'check']]]);
});

function monthlyPlan(array $data = []): PmPlan
{
    return app(SavePmPlan::class)->handle(null, $data + [
        'contract_id' => test()->contract->id, 'title' => 'PM Acme', 'interval_months' => 1, 'assignee_id' => test()->tech->id,
    ]);
}

// --- the daily run ------------------------------------------------------------------

it('makes the work order of a round N days before its period, once', function () {
    $plan = monthlyPlan();
    // round 2 starts 2026-02-01: made 14 days ahead (the default), on 2026-01-18
    expect($plan->visits()->pluck('round')->all())->toBe([1]);

    $this->travelTo('2026-01-17 06:00');
    $this->artisan('pm:generate-rounds')->assertSuccessful();
    expect($plan->visits()->count())->toBe(1);

    $this->travelTo('2026-01-18 06:00');
    $this->artisan('pm:generate-rounds')->assertSuccessful();
    $round2 = $plan->visits()->where('round', 2)->first();
    expect($round2->only(['status', 'assignee_id']))->toBe(['status' => PmVisit::STATUS_SCHEDULED, 'assignee_id' => $this->tech->id])
        ->and($round2->due_on->toDateString())->toBe('2026-02-28');

    // run again (or twice at once): never a second work order of a round
    $this->artisan('pm:generate-rounds');
    expect(app(SchedulePmVisits::class)->handle($plan))->toBe(0)
        ->and($plan->visits()->count())->toBe(2)
        ->and(fn () => $plan->visits()->create([
            'round' => 2, 'period_starts_on' => '2026-02-01', 'due_on' => '2026-02-28', 'contract_id' => $plan->contract_id,
            'customer_id' => $plan->customer_id, 'visit_no' => 'PM-X', 'status' => PmVisit::STATUS_SCHEDULED,
        ]))->toThrow(UniqueConstraintViolationException::class);
});

it('skips plans switched off, plans ended and companies with the PM module off', function () {
    $plan = monthlyPlan();
    $short = monthlyPlan(['contract_id' => createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'])->id, 'ends_on' => '2026-01-31']);
    $plan->update(['active' => false]);

    $this->travelTo('2026-03-20 06:00');
    $this->artisan('pm:generate-rounds');
    expect($plan->visits()->count())->toBe(1)
        ->and($short->visits()->count())->toBe(1)
        ->and(app(SchedulePmVisits::class)->upcoming($short))->toBe([]);

    $plan->update(['active' => true]);
    Feature::for($this->tenant)->deactivate(Modules::feature('maintenance'));
    $this->artisan('pm:generate-rounds');
    expect($plan->visits()->count())->toBe(1);

    Feature::for($this->tenant)->activate(Modules::feature('maintenance'));
    $this->artisan('pm:generate-rounds');
    // rounds 2 (Feb, ended) is not made late; 3 (Mar) and 4 (from 2026-04-01, made from 2026-03-18) are
    expect($plan->visits()->orderBy('round')->pluck('round')->all())->toBe([1, 3, 4]);
});

it('cuts rounds every 1, 3, 6 or 12 months, keeping to the month ends', function () {
    expect(collect(SchedulePmVisits::periods('2026-01-31', '2026-04-30', 1))->pluck('due_on', 'period_starts_on')->all())->toBe([
        '2026-01-31' => '2026-02-27',
        '2026-02-28' => '2026-03-30',
        '2026-03-31' => '2026-04-30',
    ])
        ->and(collect(SchedulePmVisits::periods('2026-01-01', '2026-12-31', 3))->pluck('due_on')->all())->toBe(['2026-03-31', '2026-06-30', '2026-09-30', '2026-12-31'])
        ->and(collect(SchedulePmVisits::periods('2026-03-01', '2027-02-28', 6))->pluck('due_on')->all())->toBe(['2026-08-31', '2027-02-28'])
        ->and(collect(SchedulePmVisits::periods('2026-01-01', '2028-12-31', 12))->pluck('due_on')->all())->toBe(['2026-12-31', '2027-12-31', '2028-12-31']);
});

it('makes them as many days ahead as the company sets', function () {
    $this->actingAs($this->admin)->put('/pm-settings', [
        'review_step' => true, 'approval_step' => true, 'require_technician_signature' => true, 'require_customer_signature' => false,
        'require_approver_signature' => false, 'sign_link_days' => 7, 'share_days' => 30, 'report_note' => null, 'generate_days_ahead' => 45,
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put('/pm-settings', ['generate_days_ahead' => 91])->assertSessionHasErrors('generate_days_ahead');

    // 2026-01-15 + 45 days reaches round 2 (from 2026-02-01) and round 3 (from 2026-03-01)
    $plan = monthlyPlan();
    expect($plan->visits()->orderBy('round')->pluck('round')->all())->toBe([1, 2, 3]);
});

it('makes the work orders of each company inside that company', function () {
    $mine = monthlyPlan();
    $other = createTenant('other');
    $theirs = asTenant($other, function () {
        $customer = createCustomer();
        $contract = createContract($customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);

        return app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'Theirs', 'interval_months' => 1]);
    });

    $this->travelTo('2026-01-20 06:00');
    // the action sees only the current company's plans
    expect(app(GeneratePmRounds::class)->handle())->toBe(1)
        ->and(asTenant($other, fn () => $theirs->visits()->count()))->toBe(1);

    $this->artisan('pm:generate-rounds');
    expect(asTenant($other, fn () => $theirs->visits()->orderBy('round')->pluck('visit_no')->all()))->toBe(['PM-2569-00001', 'PM-2569-00002'])
        ->and($mine->visits()->orderBy('round')->pluck('visit_no')->all())->toBe(['PM-2569-00001', 'PM-2569-00002']);
});

// --- the plan form ---------------------------------------------------------------------

it('plans the chosen equipment of the contract within the contract dates', function () {
    $stranger = createAsset(createAssetCategory(), ['customer_id' => createCustomer()->id]);
    $payload = ['contract_id' => $this->contract->id, 'title' => 'PM UPS A', 'interval_months' => 3, 'assignee_id' => $this->tech->id];

    // the form lists the contract's equipment once a contract is picked
    $this->actingAs($this->helpdesk)->get("/pm-plans/create?contract_id={$this->contract->id}")
        ->assertInertia(fn (Assert $page) => $page->where('contractAssets', fn ($assets) => collect($assets)->pluck('id')->sort()->values()->all() === [$this->upsA->id, $this->upsB->id]));

    $this->actingAs($this->helpdesk)->post('/pm-plans', [...$payload, 'asset_ids' => [$this->upsA->id, $stranger->id]])->assertSessionHasErrors('asset_ids');
    $this->actingAs($this->helpdesk)->post('/pm-plans', [...$payload, 'starts_on' => '2025-12-01', 'ends_on' => '2027-01-31'])->assertSessionHasErrors(['starts_on', 'ends_on']);

    $this->actingAs($this->helpdesk)->post('/pm-plans', [...$payload, 'asset_ids' => [$this->upsA->id], 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'])
        ->assertSessionHasNoErrors();
    $plan = PmPlan::first();
    expect($plan->ends_on->toDateString())->toBe('2026-06-30');

    // the work order starts with the chosen equipment only
    $visit = app(StartPmVisit::class)->handle($plan->visits()->first(), $this->tech);
    expect($visit->items()->pluck('asset_id')->all())->toBe([$this->upsA->id]);

    $this->actingAs($this->helpdesk)->get("/pm-plans/{$plan->id}")
        ->assertInertia(fn (Assert $page) => $page->where('plan.assets', fn ($assets) => collect($assets)->pluck('name')->all() === ['UPS Alpha'])
            ->where('upcoming', fn ($rounds) => collect($rounds)->pluck('round')->all() === [2]));
});

it('shows the rounds still to come on the calendar, to staff', function () {
    monthlyPlan();

    $this->actingAs($this->helpdesk)->get('/pm-calendar?month=2026-03')
        ->assertInertia(fn (Assert $page) => $page
            ->where('projected', fn ($rounds) => collect($rounds)->map(fn ($r) => [$r['round'], $r['calendar_date'], $r['creates_on']])->all() === [[3, '2026-03-31', '2026-02-15']])
            ->where('projected.0.customer', 'Acme'));

    $customerUser = userWithRole('customer_it', ['customer_id' => $this->customer->id]);
    $this->actingAs($customerUser)->get('/pm-calendar?month=2026-03')->assertInertia(fn (Assert $page) => $page->where('projected', []));
});
