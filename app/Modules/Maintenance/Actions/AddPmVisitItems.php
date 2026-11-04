<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Support\PmTemplateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts equipment on a work order that is still to do (draft, scheduled, in progress or sent
 * back), each with a copy of its template (PmTemplateResolver, or one picked by hand). Only assets of the work order's
 * customer; those already on it are skipped.
 */
class AddPmVisitItems
{
    public function __construct(private AssetDetails $assetDetails, private LogPmEvent $logEvent) {}

    /**
     * @param  list<int>  $assetIds
     * @param  array<int, string>  $chosen  a form picked by hand (template code by asset id), over the one resolved
     * @return int how many were added
     */
    public function handle(PmVisit $visit, array $assetIds, User $actor, array $chosen = []): int
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages(['asset_ids' => __('maintenance.visits.not_open')]);
        }

        $assets = $this->assetDetails->handle(array_values(array_unique($assetIds)));
        if (count($assets) !== count(array_unique($assetIds))) {
            throw ValidationException::withMessages(['asset_ids' => __('maintenance.visits.asset_not_found')]);
        }
        foreach ($assets as $asset) {
            if ((int) $asset['customer_id'] !== (int) $visit->customer_id) {
                throw ValidationException::withMessages(['asset_ids' => __('maintenance.visits.asset_other_customer', ['code' => $asset['asset_code']])]);
            }
        }

        $templates = PmTemplateResolver::load($visit->pm_plan_id);
        foreach ($chosen as $code) {
            if ($templates->template((string) $code) === null) {
                throw ValidationException::withMessages(['templates' => __('maintenance.visits.template_not_found')]);
            }
        }

        return DB::transaction(function () use ($visit, $assets, $actor, $templates, $chosen) {
            $existing = $visit->items()->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
            $added = [];

            foreach ($assets as $asset) {
                if (in_array((int) $asset['id'], $existing, true)) {
                    continue;
                }
                $picked = isset($chosen[$asset['id']]) ? $templates->template((string) $chosen[$asset['id']]) : null;
                $visit->items()->create(['asset_id' => $asset['id'], ...PmTemplateResolver::itemColumns($picked ?? $templates->for($asset))]);
                $added[] = $asset['asset_code'];
            }

            if ($added !== []) {
                $this->logEvent->handle($visit, PmVisitEvent::TYPE_ITEM_ADDED, $actor, implode(', ', $added), null, null, ['assets' => $added]);
            }

            return count($added);
        });
    }
}
