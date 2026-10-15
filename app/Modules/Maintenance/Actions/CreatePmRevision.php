<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a work order the customer signed: its results stay as signed, and a revision is made —
 * the same number with -R1, -R2, … — with a copy of the equipment and results (not the signatures,
 * photos or repair tickets), back with the technician to correct and have signed again. The signed
 * one is closed as cancelled, saying which revision replaces it; both keep the reason in their history.
 */
class CreatePmRevision
{
    public function __construct(private LogPmEvent $logEvent) {}

    public function handle(PmVisit $visit, User $actor, string $reason): PmVisit
    {
        return DB::transaction(function () use ($visit, $actor, $reason) {
            $visit = PmVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if (! $visit->isLocked() || $visit->status === PmVisit::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['action' => __('maintenance.visits.not_allowed')]);
            }

            $base = preg_replace('/-R\d+$/', '', $visit->visit_no);
            $number = 1;
            while (PmVisit::withTrashed()->where('visit_no', "{$base}-R{$number}")->exists()) {
                $number++;
            }

            $revision = $visit->replicate([
                'ulid', 'tracking_token', 'status', 'locked_at', 'completed_at', 'submitted_by', 'reviewed_at', 'reviewed_by',
                'approved_at', 'approved_by', 'published_at', 'published_by', 'closed_at', 'closed_by', 'cancelled_at',
                'current_version', 'published_version', 'revision_note', 'revision_count', 'departed_at',
            ]);
            $revision->fill([
                'visit_no' => "{$base}-R{$number}",
                'status' => PmVisit::STATUS_REVISION_REQUIRED,
                'revision_of_id' => $visit->id,
                'revision_note' => trim($reason),
                'revision_count' => 0,
                'current_version' => 0,
                'published_version' => 0,
            ])->save();

            foreach ($visit->items()->orderBy('id')->get() as $item) {
                $copy = $item->replicate(['pm_visit_id', 'ticket_id', 'draft']);
                $copy->pm_visit_id = $revision->id;
                $copy->save();
            }

            $from = $visit->status;
            $visit->update([
                'status' => PmVisit::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'summary' => __('maintenance.revisions.replaced_by', ['no' => $revision->visit_no, 'reason' => trim($reason)]),
            ]);

            $this->logEvent->handle($visit, PmVisitEvent::TYPE_REVISED, $actor, $reason, $from, PmVisit::STATUS_CANCELLED, ['revision' => $revision->visit_no]);
            $this->logEvent->handle($revision, PmVisitEvent::TYPE_REVISED, $actor, $reason, null, $revision->status, ['revision_of' => $visit->visit_no]);
            activity()->performedOn($visit)->causedBy($actor)->event('pm_revised')
                ->withProperties(['visit_no' => $visit->visit_no, 'revision' => $revision->visit_no, 'reason' => $reason])
                ->log(__('maintenance.log.revised', ['no' => $visit->visit_no, 'revision' => $revision->visit_no]));

            return $revision;
        });
    }
}
