<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmTemplateResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gives a piece of equipment on a work order still to do another form (the newest published version of
 * the code), when the one picked for it was wrong. Only while nothing was recorded on it — no result,
 * answers, photos or repair ticket: recorded work is never thrown away. What was only autosaved (draft)
 * goes, the page asks first. With $remember the asset keeps the form for later work orders
 * (AssignPmTemplateToAsset) — and so do the other work orders still to do that have it: the ones
 * made already, only where nothing was recorded or drafted on it. Work recorded keeps the form it was
 * done with (its copy of the questions): results, reports and signatures are never rewritten. Kept in
 * the history of each work order changed.
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
            $code = $this->assetDetails->handle([$item->asset_id])[$item->asset_id]['asset_code'] ?? null;
            $others = $remember ? $this->others($visit, $item, $template->code) : collect();
            $this->logEvent->handle($visit, PmVisitEvent::TYPE_TEMPLATE_CHANGED, $actor, trim(($code ?? '').' '.($from ?? '-').' → '.$template->code), null, null, [
                'asset_id' => $item->asset_id, 'from' => $from, 'to' => $template->code, 'version' => $template->version, 'remember' => $remember,
                'others' => $others->map(fn (PmVisitItem $other) => $other->visit->visit_no)->unique()->values()->all(),
            ]);
            if (! $remember) {
                return;
            }

            $this->assignTemplate->handle($item->asset_id, $template->code);
            foreach ($others as $other) {
                $was = $other->template_code;
                $other->update([...PmTemplateResolver::itemColumns($template), 'answers' => [], 'remarks' => null, 'instrument_id' => null]);
                $this->logEvent->handle($other->visit, PmVisitEvent::TYPE_TEMPLATE_CHANGED, $actor, trim(($code ?? '').' '.($was ?? '-').' → '.$template->code), null, null, [
                    'asset_id' => $item->asset_id, 'from' => $was, 'to' => $template->code, 'version' => $template->version, 'remember' => true,
                    'because_of' => $visit->visit_no,
                ]);
            }
        });
    }

    /**
     * The asset on the other work orders still to do, with another form, nothing recorded and nothing
     * drafted on it (a draft is someone's work in the making: left as it is).
     *
     * @return Collection<int, PmVisitItem>
     */
    private function others(PmVisit $visit, PmVisitItem $item, string $templateCode): Collection
    {
        return PmVisitItem::query()
            ->with(['visit', 'media'])
            ->where('asset_id', $item->asset_id)
            ->where('pm_visit_id', '!=', $visit->id)
            ->where(fn ($q) => $q->whereNull('template_code')->orWhere('template_code', '!=', $templateCode))
            ->whereHas('visit', fn ($q) => $q->whereIn('status', PmVisit::OPEN_STATUSES)->whereNull('locked_at'))
            ->get()
            ->filter(fn (PmVisitItem $other) => $other->visit->isOpen() && self::changeable($other) && empty($other->draft))
            ->values();
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
