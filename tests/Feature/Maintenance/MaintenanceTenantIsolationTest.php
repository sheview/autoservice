<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Platform\Support\Modules;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/**
 * A plan with a started round (items) in the current tenant.
 */
function startedPmPlan(): array
{
    $admin = userWithRole('admin_company');
    $customer = createCustomer();
    $contract = createContract($customer);
    $contract->contractAssets()->create(['asset_id' => createAsset(createAssetCategory(), ['customer_id' => $customer->id])->id]);
    $checklist = PmChecklist::create(['name' => 'General', 'items' => [['key' => 'look', 'label' => 'Look', 'type' => 'check']]]);

    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM', 'interval_months' => 6, 'assignee_id' => $admin->id]);
    $visit = app(StartPmVisit::class)->handle($plan->visits()->orderBy('round')->first(), $admin);

    return [$admin, $plan, $visit, $checklist, $contract];
}

it('keeps checklists, plans, rounds and their numbers per tenant', function () {
    [$admin, $mine, $myVisit] = startedPmPlan();

    $other = createTenant('other');
    [, $theirPlan, $theirVisit, $theirChecklist, $theirContract] = asTenant($other, fn () => startedPmPlan());

    expect($myVisit->visit_no)->toBe($theirVisit->visit_no);

    $this->actingAs($admin)->get('/pm-plans')->assertInertia(fn (Assert $page) => $page->where('plans.total', 1));
    $this->actingAs($admin)->get('/pm-visits?status=all')->assertInertia(fn (Assert $page) => $page->where('visits.total', 2));
    $this->actingAs($admin)->get('/pm-checklists')->assertInertia(fn (Assert $page) => $page->where('checklists.total', 1));

    $theirItem = asTenant($other, fn () => $theirVisit->items()->first());
    $this->actingAs($admin)->get("/pm-plans/{$theirPlan->id}")->assertNotFound();
    $this->actingAs($admin)->delete("/pm-plans/{$theirPlan->id}")->assertNotFound();
    $this->actingAs($admin)->get("/pm-visits/{$theirVisit->ulid}")->assertNotFound();
    $this->actingAs($admin)->post("/pm-visits/{$theirVisit->ulid}/cancel", ['reason' => 'x'])->assertNotFound();
    $this->actingAs($admin)->put("/pm-checklists/{$theirChecklist->id}", ['name' => 'x', 'items' => []])->assertNotFound();
    // an item of another tenant, even under my own round
    $this->actingAs($admin)->put("/pm-visits/{$myVisit->ulid}/items/{$theirItem->id}", ['result' => 'ok'])->assertNotFound();

    // a contract of the other tenant cannot be planned
    $this->actingAs($admin)->post('/pm-plans', ['contract_id' => $theirContract->id, 'title' => 'x', 'interval_months' => 3])
        ->assertSessionHasErrors('contract_id');

    // and raw queries only see this tenant (RLS)
    expect(DB::table('pm_plans')->pluck('id')->all())->toBe([$mine->id])
        ->and(DB::table('pm_visits')->where('pm_plan_id', $theirPlan->id)->count())->toBe(0)
        ->and(DB::table('pm_visit_items')->where('pm_visit_id', $theirVisit->id)->count())->toBe(0)
        ->and(DB::table('pm_checklists')->count())->toBe(1)
        ->and(DB::table('pm_number_sequences')->count())->toBe(1)
        ->and(PmPlan::count())->toBe(1)
        ->and(PmVisit::where('pm_plan_id', $mine->id)->count())->toBe(2);
});

it('hides the module when the tenant has it switched off', function () {
    [$admin, $plan, $visit] = startedPmPlan();

    Feature::for($admin->tenant)->deactivate(Modules::feature('maintenance'));

    $this->actingAs($admin)->get('/pm-visits')->assertNotFound();
    $this->actingAs($admin)->get("/pm-plans/{$plan->id}")->assertNotFound();
    $this->actingAs($admin)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($items) => collect($items)->pluck('href')->filter(fn ($href) => str_contains($href, '/pm-'))->isEmpty()));
});
