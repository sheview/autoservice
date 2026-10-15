<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\StartAssetImport;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmPlan;
use Illuminate\Http\UploadedFile;

/**
 * "นำเข้าอุปกรณ์ (Excel)" of a PM plan: the asset import (StartAssetImport, in the background) with
 * the plan as its context — every row's asset becomes the customer's, joins the plan's contract and
 * the plan (AddImportedAssetToPmPlan) with the PM form of its row.
 */
class StartPmPlanImport
{
    /** The column of the PM form (a template's code) besides the asset columns. */
    public const FORM_COLUMN = 'pm_form';

    public function __construct(private StartAssetImport $startImport, private ContractDetails $contractDetails) {}

    public function handle(PmPlan $plan, User $user, UploadedFile $file): void
    {
        $contract = $this->contractDetails->handle([$plan->contract_id])[$plan->contract_id] ?? null;

        $this->startImport->handle($user, $file, [
            'pm_plan_id' => $plan->id,
            'contract_id' => $plan->contract_id,
            'contract_no' => $contract['contract_no'] ?? null,
            'customer_id' => $plan->customer_id,
            'columns' => [self::FORM_COLUMN => __('maintenance.plans.form_column')],
        ]);
    }
}
