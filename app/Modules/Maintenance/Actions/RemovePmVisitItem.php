<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a piece of equipment off a work order that is still to do, while nothing was recorded on
 * it (no result, no photos): recorded work is never thrown away. Kept in the work order's history.
 */
class RemovePmVisitItem
{
    public function __construct(private AssetDetails $assetDetails, private LogPmEvent $logEvent) {}

    public function handle(PmVisit $visit, PmVisitItem $item, User $actor): void
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages(['item' => __('maintenance.visits.not_open')]);
        }
        if ($item->result !== PmVisitItem::RESULT_PENDING || $item->hasMedia(PmVisitItem::PHOTOS) || $item->ticket_id !== null) {
            throw ValidationException::withMessages(['item' => __('maintenance.visits.item_has_work')]);
        }

        DB::transaction(function () use ($visit, $item, $actor) {
            $code = $this->assetDetails->handle([$item->asset_id])[$item->asset_id]['asset_code'] ?? null;
            $item->delete();
            $this->logEvent->handle($visit, PmVisitEvent::TYPE_ITEM_REMOVED, $actor, $code, null, null, ['asset_id' => $item->asset_id]);
        });
    }
}
