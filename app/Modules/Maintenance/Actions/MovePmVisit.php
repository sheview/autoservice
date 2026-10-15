<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Maintenance\Support\PmWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a work order along PmWorkflow (except start and submit, which have their own actions),
 * stamps who did it and when, and writes it to the work order's history and the activity log.
 * Approving freezes the report as a new version (CreatePmReportVersion). The note is the reason
 * of a return, reopen or cancel, or a remark on the others.
 */
class MovePmVisit
{
    public function __construct(private LogPmEvent $logEvent, private CreatePmReportVersion $createVersion) {}

    public function handle(PmVisit $visit, string $action, User $actor, ?string $note = null): PmVisit
    {
        return DB::transaction(function () use ($visit, $action, $actor, $note) {
            // Read the status under a lock: two people pressing at once move it only once. Who may make
            // a move can depend on the status (a return: review or approve), so a status that changed
            // since the caller checked the permission is refused too.
            $checked = $visit->status;
            $visit = PmVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($visit->status !== $checked) {
                throw ValidationException::withMessages(['action' => __('maintenance.visits.changed_meanwhile')]);
            }
            $this->check($visit, $action, $note);

            $from = $visit->status;
            $to = PmWorkflow::target($action, PmSettings::of());
            $note = filled($note) ? trim($note) : null;

            $visit->fill([
                'status' => $to,
                ...match ($action) {
                    'review' => ['reviewed_at' => now(), 'reviewed_by' => $actor->id],
                    'return', 'reopen' => ['revision_note' => $note, 'revision_count' => $visit->revision_count + 1],
                    'approve' => ['approved_at' => now(), 'approved_by' => $actor->id],
                    'publish' => ['published_at' => now(), 'published_by' => $actor->id, 'published_version' => $visit->current_version],
                    'close' => ['closed_at' => now(), 'closed_by' => $actor->id],
                    // The reason stays readable on the work order, as before the workflow.
                    'cancel' => ['cancelled_at' => now(), 'summary' => $note],
                    default => [],
                },
            ])->save();

            $this->logEvent->handle($visit, $action, $actor, $note, $from, $to);

            if ($action === 'approve') {
                $this->createVersion->handle($visit, $actor);
            }

            activity()->performedOn($visit)->causedBy($actor)->event('pm_'.$action)
                ->withProperties(['visit_no' => $visit->visit_no, 'from' => $from, 'to' => $to, 'note' => $note])
                ->log(__('maintenance.log.'.$action, ['no' => $visit->visit_no]));

            return $visit;
        });
    }

    /**
     * The move is allowed from this status, has its reason when it needs one, and is not one
     * that has its own action.
     */
    public static function check(PmVisit $visit, string $action, ?string $note): void
    {
        if (isset(PmWorkflow::OWN_ACTION[$action]) || ! PmWorkflow::allows($visit, $action)) {
            throw ValidationException::withMessages(['action' => __('maintenance.visits.not_allowed')]);
        }
        if (in_array($action, PmWorkflow::NEEDS_NOTE, true) && blank($note)) {
            throw ValidationException::withMessages(['note' => __('maintenance.visits.reason_required')]);
        }
    }
}
