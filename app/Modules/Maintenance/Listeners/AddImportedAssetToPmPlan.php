<?php

namespace App\Modules\Maintenance\Listeners;

use App\Modules\Asset\Events\AssetImportRowSaved;
use App\Modules\Maintenance\Actions\AddAssetToPmPlan;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmPlan;
use Illuminate\Validation\ValidationException;

/**
 * A row of an equipment import asked from a PM plan (StartPmPlanImport): the asset joins the plan,
 * with the PM form of the row (pm_plan_assets.template_code) — a published template's code; empty =
 * the template of its kind, as before (AddAssetToPmPlan). An unknown form refuses the row (nothing of it is kept).
 */
class AddImportedAssetToPmPlan
{
    public function __construct(private AddAssetToPmPlan $addToPlan) {}

    public function handle(AssetImportRowSaved $event): void
    {
        $planId = $event->import->context['pm_plan_id'] ?? null;
        if ($planId === null || ! ($plan = PmPlan::query()->find($planId))) {
            return;
        }

        $form = trim((string) ($event->extra['pm_form'] ?? ''));
        if ($form !== '' && ! PmChecklist::query()->where('code', $form)->where('status', PmChecklist::STATUS_PUBLISHED)->exists()) {
            throw ValidationException::withMessages(['pm_form' => __('maintenance.plans.unknown_form', ['value' => $form])]);
        }

        $this->addToPlan->handle($plan, $event->asset->id, $form);
    }
}
