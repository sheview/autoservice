<?php

use App\Modules\Maintenance\Actions\MovePmVisit;
use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Actions\SubmitPmVisit;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-05-10 10:00');
    $this->admin = userWithRole('admin_company');
    $this->helpdesk = userWithRole('helpdesk');
    $this->tech = userWithRole('technician');
    $this->otherTech = userWithRole('technician');

    $this->acme = createCustomer(['name' => 'Acme']);
    $this->beta = createCustomer(['name' => 'Beta']);
    $plan = function ($customer, $tech) {
        $asset = createAsset(createAssetCategory(), ['customer_id' => $customer->id]);
        $contract = createContract($customer, ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $contract->contractAssets()->create(['asset_id' => $asset->id]);

        return app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM '.$customer->name, 'interval_months' => 1, 'assignee_id' => $tech->id]);
    };
    // Monthly rounds from May: Acme for tech, Beta for the other technician.
    $this->acmeVisits = $plan($this->acme, $this->tech)->visits()->orderBy('round')->get();
    $this->betaVisits = $plan($this->beta, $this->otherTech)->visits()->orderBy('round')->get();

    // Acme's May round handed in, waiting for review.
    $may = $this->acmeVisits[0];
    app(StartPmVisit::class)->handle($may, $this->tech);
    $may->items()->update(['result' => PmVisitItem::RESULT_ISSUE, 'note' => 'x']);
    app(SubmitPmVisit::class)->handle($may, $this->tech, ['technician_signature' => signaturePng()]);
});

it('shows where PM stands and what waits for the user', function () {
    $this->actingAs($this->helpdesk)->get('/pm-dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Dashboard')
        ->where('counts.submitted', 1)
        ->where('counts.scheduled', $this->acmeVisits->count() + $this->betaVisits->count() - 1)
        ->where('counts.due_this_month', 1)
        ->where('thisMonth.due', 2)
        ->where('thisMonth.done', 1)
        ->where('thisMonth.compliance', 50)
        ->where('waiting.review.0.visit_no', $this->acmeVisits[0]->visit_no)
        ->where('waiting.approve', [])
        ->has('trend', 12)
        ->where('history.0.type', 'submit'));

    // Beta's May round, never started, is overdue once the month has passed.
    $this->travelTo('2026-06-05');
    $this->actingAs($this->admin)->get('/pm-dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('counts.overdue', 1)
        ->where('waiting.overdue.0.visit_no', $this->betaVisits[0]->visit_no));
});

it('keeps a technician to their own work orders, and customers out', function () {
    $this->actingAs($this->otherTech)->get('/pm-dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('counts.submitted', 0)
        ->where('counts.scheduled', $this->betaVisits->count())
        // A technician does not review.
        ->where('waiting.review', []));

    $this->actingAs(userWithRole('customer_it', ['customer_id' => $this->acme->id]))->get('/pm-dashboard')->assertForbidden();
    $this->actingAs(userWithRole('customer_it', ['customer_id' => $this->acme->id]))->get('/pm-reports')->assertForbidden();
});

it('reports a period by figures and by customer, with its work orders', function () {
    app(MovePmVisit::class)->handle($this->acmeVisits[0]->fresh(), 'review', $this->helpdesk);
    app(MovePmVisit::class)->handle($this->acmeVisits[0]->fresh(), 'approve', $this->admin);

    $this->actingAs($this->helpdesk)->get('/pm-reports?from=2026-05-01&to=2026-05-31')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Maintenance/Reports')
        ->where('summary.due', 2)
        ->where('summary.done', 1)
        ->where('summary.results.issue', 1)
        ->where('byCustomer', fn ($rows) => collect($rows)->pluck('customer')->sort()->values()->all() === ['Acme', 'Beta'])
        ->where('visits.total', 2));

    $this->actingAs($this->helpdesk)->get('/pm-reports?from=2026-05-01&to=2026-05-31&customer_id='.$this->acme->id.'&status=approved')
        ->assertInertia(fn (Assert $page) => $page->where('summary.due', 1)->where('visits.total', 1)->where('visits.data.0.current_version', 1));
    // Dates the wrong way round are swapped; a technician sees only their own.
    $this->actingAs($this->otherTech)->get('/pm-reports?from=2026-05-31&to=2026-05-01')
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-05-01')->where('visits.total', 1));
    expect(PmVisit::count())->toBeGreaterThan(2);
});
