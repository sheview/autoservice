<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes a plan and its rounds. A plan with a round that was started keeps its record of
 * work, so it cannot be deleted (cancel the remaining rounds instead).
 */
class DeletePmPlan
{
    public function handle(PmPlan $plan): void
    {
        if ($plan->visits()->where('status', '!=', PmVisit::STATUS_SCHEDULED)->exists()) {
            throw ValidationException::withMessages(['plan' => __('maintenance.plans.has_work')]);
        }

        DB::transaction(function () use ($plan) {
            $plan->visits()->each(fn (PmVisit $visit) => $visit->delete());
            $plan->delete();
        });
    }
}
