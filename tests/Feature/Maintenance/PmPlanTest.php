<?php

use App\Modules\Maintenance\Actions\SchedulePmVisits;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-01-15 10:00');

    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician', ['name' => 'Tech One']);
    $this->customer = createCustomer(['name' => 'Acme']);
    $this->contract = createContract($this->customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'pm_interval_months' => 3]);
});

function planPayload(array $overrides = []): array
{
    return $overrides + [
        'contract_id' => test()->contract->id,
        'title' => 'PM Acme 2569',
        'interval_months' => 3,
        'assignee_id' => test()->tech->id,
    ];
}

it('cuts the contract period into rounds due at the end of each period', function () {
    expect(SchedulePmVisits::periods('2026-01-01', '2026-12-31', 3))->toBe([
        ['round' => 1, 'period_starts_on' => '2026-01-01', 'due_on' => '2026-03-31'],
        ['round' => 2, 'period_starts_on' => '2026-04-01', 'due_on' => '2026-06-30'],
        ['round' => 3, 'period_starts_on' => '2026-07-01', 'due_on' => '2026-09-30'],
        ['round' => 4, 'period_starts_on' => '2026-10-01', 'due_on' => '2026-12-31'],
    ])
        // a period that does not fit ends with the contract
        ->and(SchedulePmVisits::periods('2026-01-31', '2026-05-15', 2))->toBe([
            ['round' => 1, 'period_starts_on' => '2026-01-31', 'due_on' => '2026-03-30'],
            ['round' => 2, 'period_starts_on' => '2026-03-31', 'due_on' => '2026-05-15'],
        ])
        // a leftover shorter than half a round joins the last round
        ->and(SchedulePmVisits::periods('2026-01-15', '2027-01-15', 6))->toBe([
            ['round' => 1, 'period_starts_on' => '2026-01-15', 'due_on' => '2026-07-14'],
            ['round' => 2, 'period_starts_on' => '2026-07-15', 'due_on' => '2027-01-15'],
        ]);
});

it('creates a plan with its rounds, numbered and given to the assignee', function () {
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload())->assertSessionHasNoErrors();

    $plan = PmPlan::first();
    expect($plan->customer_id)->toBe($this->customer->id)
        ->and($plan->starts_on->toDateString())->toBe('2026-01-01')
        ->and($plan->ends_on->toDateString())->toBe('2026-12-31')
        ->and($plan->visits()->orderBy('round')->get()->map->only(['visit_no', 'round', 'due_on', 'status', 'assignee_id'])->map(fn ($v) => [
            $v['visit_no'], $v['round'], $v['due_on']->toDateString(), $v['status'], $v['assignee_id'],
        ])->all())->toBe([
            ['PM-2569-00001', 1, '2026-03-31', 'scheduled', $this->tech->id],
            ['PM-2569-00002', 2, '2026-06-30', 'scheduled', $this->tech->id],
            ['PM-2569-00003', 3, '2026-09-30', 'scheduled', $this->tech->id],
            ['PM-2569-00004', 4, '2026-12-31', 'scheduled', $this->tech->id],
        ]);

    $this->actingAs($this->helpdesk)->get("/pm-plans/{$plan->id}")
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Plans/Show')
            ->where('plan.contract.contract_no', $this->contract->contract_no)
            ->has('visits', 4));

    $this->actingAs($this->helpdesk)->get('/pm-plans')
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Plans/Index')
            ->where('plans.total', 1)
            ->where('plans.data.0.visits_count', 4)
            ->where('plans.data.0.customer', 'Acme'));
});

it('does not schedule rounds that already ended when planned mid-contract', function () {
    $this->travelTo('2026-05-01');

    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload())->assertSessionHasNoErrors();

    expect(PmVisit::orderBy('round')->pluck('round')->all())->toBe([2, 3, 4]);
});

it('accepts only an active running contract without a plan, and assignees who may do PM', function () {
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload());
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload())->assertSessionHasErrors('contract_id');

    $ended = createContract($this->customer, ['starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload(['contract_id' => $ended->id]))->assertSessionHasErrors('contract_id');

    $draft = createContract($this->customer, ['status' => 'draft']);
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload(['contract_id' => $draft->id]))->assertSessionHasErrors('contract_id');

    $office = userWithRole('user');
    $other = createContract($this->customer);
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload(['contract_id' => $other->id, 'assignee_id' => $office->id]))
        ->assertSessionHasErrors('assignee_id');

    // the form only offers contracts without a plan
    $this->actingAs($this->helpdesk)->get('/pm-plans/create')
        ->assertInertia(fn (Assert $page) => $page->component('Maintenance/Plans/Form')
            ->where('contracts', fn ($contracts) => collect($contracts)->pluck('id')->all() === [$other->id]));
});

it('re-schedules on a new interval until a round has started, and passes on a new assignee', function () {
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload(['assignee_id' => null]));
    $plan = PmPlan::first();

    $this->actingAs($this->helpdesk)->put("/pm-plans/{$plan->id}", planPayload(['interval_months' => 6, 'assignee_id' => $this->tech->id]))
        ->assertSessionHasNoErrors();
    expect($plan->visits()->pluck('due_on')->map->toDateString()->all())->toBe(['2026-06-30', '2026-12-31'])
        ->and($plan->visits()->pluck('assignee_id')->unique()->all())->toBe([$this->tech->id])
        ->and(PmVisit::onlyTrashed()->count())->toBe(4);

    $tech2 = userWithRole('technician');
    $first = $plan->visits()->orderBy('round')->first();
    $first->update(['assignee_id' => $tech2->id]); // moved to someone else by hand
    $this->actingAs($this->helpdesk)->put("/pm-plans/{$plan->id}", planPayload(['interval_months' => 6, 'assignee_id' => null]));
    expect($first->fresh()->assignee_id)->toBe($tech2->id)
        ->and($plan->visits()->where('round', 2)->value('assignee_id'))->toBeNull();

    $first->update(['status' => PmVisit::STATUS_IN_PROGRESS]);
    $this->actingAs($this->helpdesk)->put("/pm-plans/{$plan->id}", planPayload(['interval_months' => 3]))
        ->assertSessionHasErrors('interval_months');
});

it('deletes a plan only while none of its rounds has started', function () {
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload());
    $plan = PmPlan::first();
    $admin = userWithRole('admin_company');

    // helpdesk has no pm.delete
    $this->actingAs($this->helpdesk)->delete("/pm-plans/{$plan->id}")->assertForbidden();

    $plan->visits()->first()->update(['status' => PmVisit::STATUS_IN_PROGRESS]);
    $this->actingAs($admin)->delete("/pm-plans/{$plan->id}")->assertSessionHasErrors('plan');

    $plan->visits()->update(['status' => PmVisit::STATUS_SCHEDULED]);
    $this->actingAs($admin)->delete("/pm-plans/{$plan->id}")->assertRedirect('/pm-plans');
    expect(PmPlan::count())->toBe(0)
        ->and(PmVisit::count())->toBe(0)
        ->and(PmPlan::withTrashed()->count())->toBe(1);
});

it('lets technicians look at plans but not change them', function () {
    $this->actingAs($this->helpdesk)->post('/pm-plans', planPayload());
    $plan = PmPlan::first();

    $this->actingAs($this->tech)->get('/pm-plans')->assertOk();
    $this->actingAs($this->tech)->get("/pm-plans/{$plan->id}")->assertOk();
    $this->actingAs($this->tech)->get('/pm-plans/create')->assertForbidden();
    $this->actingAs($this->tech)->put("/pm-plans/{$plan->id}", planPayload())->assertForbidden();

    // a customer account never sees plans (its rounds: PmExtrasTest)
    $customerUser = userWithRole('customer', ['customer_id' => $this->customer->id]);
    $this->actingAs($customerUser)->get('/pm-plans')->assertForbidden();
    $this->actingAs($customerUser)->get("/pm-plans/{$plan->id}")->assertForbidden();
});
