<?php

use App\Modules\Maintenance\Actions\SavePmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmPlanAsset;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Support\PmTemplateResolver;

beforeEach(function () {
    $this->ups = createAssetCategory(['name' => 'UPS', 'code_prefix' => 'UPS']);
    $this->apc = createAsset($this->ups, ['brand' => 'APC', 'model' => 'Smart-UPS 3000']);
    $this->apcOther = createAsset($this->ups, ['brand' => 'APC', 'model' => 'Back-UPS 650']);
    $this->eaton = createAsset($this->ups, ['brand' => 'Eaton', 'model' => '9PX']);
    $this->pc = createAsset(createAssetCategory(['name' => 'PC', 'code_prefix' => 'PC']));
});

/** The code of the template resolved for an asset. */
function resolvedCode(PmTemplateResolver $resolver, $asset): ?string
{
    return $resolver->for($asset->only(['id', 'category_id', 'asset_brand_id', 'asset_model_id']))?->code;
}

function assignTemplate(string $code, array $match): void
{
    PmTemplateAssignment::create(['template_code' => $code, ...$match]);
}

it('picks the most specific assignment: asset, model, brand within category, category, default', function () {
    createPmTemplate(['code' => 'DEFAULT']);
    createPmTemplate(['code' => 'UPS-CAT', 'asset_category_id' => $this->ups->id]);
    foreach (['APC-BRAND', 'APC-MODEL', 'ONE-ASSET'] as $code) {
        createPmTemplate(['code' => $code]);
        PmTemplateAssignment::where('template_code', $code)->delete(); // tied below instead
    }
    assignTemplate('APC-BRAND', ['asset_category_id' => $this->ups->id, 'asset_brand_id' => $this->apc->asset_brand_id]);
    assignTemplate('APC-MODEL', ['asset_model_id' => $this->apc->asset_model_id]);
    assignTemplate('ONE-ASSET', ['asset_id' => $this->apc->id]);

    $resolver = PmTemplateResolver::load();

    expect(resolvedCode($resolver, $this->apc))->toBe('ONE-ASSET')
        ->and(resolvedCode($resolver, $this->apcOther))->toBe('APC-BRAND')
        ->and(resolvedCode($resolver, $this->eaton))->toBe('UPS-CAT')
        ->and(resolvedCode($resolver, $this->pc))->toBe('DEFAULT');

    // without the asset's own assignment, its model's applies
    PmTemplateAssignment::where('template_code', 'ONE-ASSET')->delete();
    expect(resolvedCode(PmTemplateResolver::load(), $this->apc))->toBe('APC-MODEL');
});

it('uses the plan\'s own template for an asset before any assignment', function () {
    createPmTemplate(['code' => 'UPS-CAT', 'asset_category_id' => $this->ups->id]);
    createPmTemplate(['code' => 'SPECIAL']);
    $customer = createCustomer();
    $plan = app(SavePmPlan::class)->handle(null, ['contract_id' => createContract($customer)->id, 'title' => 'PM', 'interval_months' => 6]);
    PmPlanAsset::create(['pm_plan_id' => $plan->id, 'asset_id' => $this->eaton->id, 'template_code' => 'SPECIAL']);

    expect(resolvedCode(PmTemplateResolver::load($plan->id), $this->eaton))->toBe('SPECIAL')
        ->and(resolvedCode(PmTemplateResolver::load(), $this->eaton))->toBe('UPS-CAT');
});

it('takes the newest published version and passes over drafts and archived codes', function () {
    createPmTemplate(['code' => 'UPS', 'version' => 1, 'asset_category_id' => $this->ups->id]);
    createPmTemplate(['code' => 'UPS', 'version' => 2, 'asset_category_id' => $this->ups->id]);
    createPmTemplate(['code' => 'UPS', 'version' => 3, 'status' => PmChecklist::STATUS_DRAFT, 'asset_category_id' => $this->ups->id]);

    $template = PmTemplateResolver::load()->for($this->eaton->only(['id', 'category_id', 'asset_brand_id', 'asset_model_id']));
    expect($template->version)->toBe(2);

    // a code with nothing published falls through to the next level
    PmChecklist::where('code', 'UPS')->update(['status' => PmChecklist::STATUS_ARCHIVED]);
    createPmTemplate(['code' => 'DEFAULT']);
    expect(resolvedCode(PmTemplateResolver::load(), $this->eaton))->toBe('DEFAULT');
});

it('finds nothing when no template applies', function () {
    createPmTemplate(['code' => 'UPS-CAT', 'asset_category_id' => $this->ups->id]);

    expect(resolvedCode(PmTemplateResolver::load(), $this->pc))->toBeNull();
});
