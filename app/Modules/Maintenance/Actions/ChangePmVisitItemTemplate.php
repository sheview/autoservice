<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmTemplateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a piece of equipment on a work order still to do another form (the newest published version of
 * the code), when the one picked for it was wrong. Only while nothing was recorded on it — no result,
 * answers, photos or repair ticket: recorded work is never thrown away. What was only autosaved (draft)
 * goes, the page asks first. With $remember the asset keeps the form for later work orders
 * (AssignPmTemplateToAsset). Kept in the work order's history.
 */
class ChangePmVisitItemTemplate
{
    public function __construct(
        private AssetDetails $assetDetails,
        private AssignPmTemplateToAsset $assignTemplate,
        private LogPmEvent $logEvent,
    ) {}

    public function handle(PmVisit $visit, PmVisitItem $item, string $templateCode, bool $remember, User $actor): void
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages(['template_code' => __('maintenance.visits.not_open')]);
        }
        if (! self::changeable($item)) {
            throw ValidationException::withMessages(['template_code' => __('maintenance.visits.template_has_work')]);
        }
        $template = PmTemplateResolver::load()->template($templateCode);
        if ($template === null) {
            throw ValidationException::withMessages(['template_code' => __('maintenance.visits.template_not_found')]);
        }

        DB::transaction(function () use ($visit, $item, $template, $remember, $actor) {
            $from = $item->template_code;
            $item->update([
                ...PmTemplateResolver::itemColumns($template),
                'answers' => [], 'remarks' => null, 'draft' => null, 'instrument_id' => null,
            ]);
            if ($remember) {
                $this->assignTemplate->handle($item->asset_id, $template->code);
            }

            $code = $this->assetDetails->handle([$item->asset_id])[$item->asset_id]['asset_code'] ?? null;
            $this->logEvent->handle($visit, PmVisitEvent::TYPE_TEMPLATE_CHANGED, $actor, trim(($code ?? '').' '.($from ?? '-').' → '.$template->code), null, null, [
                'asset_id' => $item->asset_id, 'from' => $from, 'to' => $template->code, 'version' => $template->version, 'remember' => $remember,
            ]);
        });
    }

    /**
     * Nothing recorded on it yet (what was only autosaved does not count).
     */
    public static function changeable(PmVisitItem $item): bool
    {
        return $item->result === PmVisitItem::RESULT_PENDING
            && $item->ticket_id === null
            && empty($item->answers)
            && ! $item->hasMedia(PmVisitItem::PHOTOS);
    }
}
