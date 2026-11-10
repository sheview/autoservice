<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AddAssetFromSite;
use App\Modules\Contract\Actions\AddAssetToContracts;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Equipment the technician finds on site that is not in the register: registered from the work order
 * with only what is typed there (AddAssetFromSite: the customer's, waiting for the office to confirm
 * it), put on the work order with its form (picked, else the one of its kind), and — unless told not
 * to — into the work order's contract and plan, so the next rounds check it too.
 */
class AddSiteEquipment
{
    public function __construct(
        private AddAssetFromSite $addAsset,
        private AddPmVisitItems $addItems,
        private AddAssetToContracts $addToContracts,
        private AddAssetToPmPlan $addToPlan,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated: the asset's fields (AddAssetFromSite), template_code, add_to_plan
     */
    public function handle(PmVisit $visit, array $data, User $actor, ?UploadedFile $photo = null): PmVisitItem
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_open')]);
        }
        $templateCode = filled($data['template_code'] ?? null) ? (string) $data['template_code'] : null;
        // Checked before anything is made (a photo saved is not taken back by the transaction); only
        // whether it is published — AddPmVisitItems copies it.
        if ($templateCode !== null && ! PmChecklist::query()->where('code', $templateCode)->where('status', PmChecklist::STATUS_PUBLISHED)->exists()) {
            throw ValidationException::withMessages(['template_code' => __('maintenance.visits.template_not_found')]);
        }

        return DB::transaction(function () use ($visit, $data, $actor, $photo, $templateCode) {
            $asset = $this->addAsset->handle(
                [...collect($data)->except(['template_code', 'add_to_plan'])->all(), 'customer_id' => $visit->customer_id],
                __('maintenance.visits.added_from_site', ['no' => $visit->visit_no, 'name' => $actor->name]),
                $photo,
            );

            if ($data['add_to_plan'] ?? true) {
                if ($visit->contract_id !== null) {
                    $this->addToContracts->handle($asset->id, [$visit->contract_id]);
                }
                if ($visit->pm_plan_id !== null && ($plan = PmPlan::query()->find($visit->pm_plan_id))) {
                    $this->addToPlan->handle($plan, $asset->id, $templateCode, keepCoverage: true);
                }
            }

            $this->addItems->handle($visit, [$asset->id], $actor, $templateCode ? [$asset->id => $templateCode] : []);

            return $visit->items()->where('asset_id', $asset->id)->sole();
        });
    }
}
