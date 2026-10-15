<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Actions\StartPmVisit;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmInstrument;
use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmSignature;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Database\QueryException;

it('keeps templates, assignments, plan assets, instruments and signatures per tenant', function () {
    $mine = createPmTemplate(['code' => 'UPS']);
    createPmInstrument(['serial_no' => 'FLUKE-1']);

    $other = createTenant('other');
    [$theirVisit, $theirPlanAsset] = asTenant($other, function () {
        createPmTemplate(['code' => 'UPS']);
        createPmInstrument(['serial_no' => 'FLUKE-1']);
        $admin = userWithRole('admin_company');
        $customer = createCustomer();
        $contract = createContract($customer);
        $asset = createAsset(createAssetCategory(), ['customer_id' => $customer->id]);
        $contract->contractAssets()->create(['asset_id' => $asset->id]);
        $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM', 'interval_months' => 6]);
        $planAsset = PmPlanAsset::create(['pm_plan_id' => $plan->id, 'asset_id' => $asset->id]);
        $visit = $plan->visits()->first();
        $visit->signatures()->create(['role' => PmSignature::ROLE_CUSTOMER, 'name' => 'ลูกค้า', 'image_path' => 'x.png', 'signed_at' => now()]);

        return [$visit, $planAsset];
    });

    expect(PmChecklist::pluck('id')->all())->toBe([$mine->id])
        ->and(PmTemplateAssignment::count())->toBe(1)
        ->and(PmInstrument::count())->toBe(1)
        ->and(PmPlanAsset::find($theirPlanAsset->id))->toBeNull()
        ->and(PmSignature::where('pm_visit_id', $theirVisit->id)->count())->toBe(0)
        ->and(PmVisit::find($theirVisit->id))->toBeNull();
});

it('keeps one live row per template code and version, per tenant', function () {
    $first = createPmTemplate(['code' => 'UPS', 'version' => 1]);
    createPmTemplate(['code' => 'UPS', 'version' => 2]);

    expect(fn () => createPmTemplate(['code' => 'UPS', 'version' => 1]))->toThrow(QueryException::class);

    // a deleted version frees its number
    $first->delete();
    expect(createPmTemplate(['code' => 'UPS', 'version' => 1])->exists)->toBeTrue();

    // another company may use the same code and version
    $other = createTenant('other');
    expect(asTenant($other, fn () => createPmTemplate(['code' => 'UPS', 'version' => 1]))->exists)->toBeTrue();
});

it('keeps an asset once per plan and instrument serials unique per tenant', function () {
    $customer = createCustomer();
    $contract = createContract($customer);
    $asset = createAsset(createAssetCategory(), ['customer_id' => $customer->id]);
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM', 'interval_months' => 6]);
    PmPlanAsset::create(['pm_plan_id' => $plan->id, 'asset_id' => $asset->id]);

    expect(fn () => PmPlanAsset::create(['pm_plan_id' => $plan->id, 'asset_id' => $asset->id]))->toThrow(QueryException::class);

    createPmInstrument(['serial_no' => 'FLUKE-1']);
    expect(fn () => createPmInstrument(['serial_no' => 'fluke-1']))->toThrow(QueryException::class);
});

it('starts a plan round with only the equipment the plan lists, recording the template used', function () {
    $admin = userWithRole('admin_company');
    $category = createAssetCategory();
    $template = createPmTemplate(['code' => 'GEN', 'items' => [['key' => 'look', 'label' => 'Look', 'type' => 'pass_fail']]]);
    $customer = createCustomer();
    $contract = createContract($customer);
    $listed = createAsset($category, ['customer_id' => $customer->id]);
    $unlisted = createAsset($category, ['customer_id' => $customer->id]);
    $contract->contractAssets()->createMany([['asset_id' => $listed->id], ['asset_id' => $unlisted->id]]);
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => $contract->id, 'title' => 'PM', 'interval_months' => 6, 'assignee_id' => $admin->id]);
    PmPlanAsset::create(['pm_plan_id' => $plan->id, 'asset_id' => $listed->id]);

    $visit = app(StartPmVisit::class)->handle($plan->visits()->orderBy('round')->first(), $admin);

    $items = $visit->items()->get();
    expect($items->pluck('asset_id')->all())->toBe([$listed->id])
        ->and($items[0]->template_code)->toBe('GEN')
        ->and($items[0]->template_version)->toBe(1)
        ->and($items[0]->pm_checklist_id)->toBe($template->id)
        ->and($items[0]->checklist[0]['key'])->toBe('look');
});
