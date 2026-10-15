<?php

namespace App\Modules\Maintenance\Support;

use App\Modules\Maintenance\Models\PmVisit as V;

/**
 * The moves of a PM work order, from which statuses each is allowed, and which policy ability
 * makes it (PmVisitPolicy):
 *
 *   schedule  draft → scheduled                              update   (a draft gets its date and technician)
 *   start     scheduled → in_progress                        perform  (StartPmVisit: lists the equipment)
 *   submit    in_progress | revision_required → submitted     perform  (SubmitPmVisit: every item checked)
 *             … → pending_approval when the company skips review (PmSettings)
 *   review    submitted → pending_approval                    review
 *   return    submitted | pending_approval → revision_required   review / approve, with the reason
 *   approve   pending_approval → approved                      approve  (freezes a report version)
 *   publish   approved → published                             publish  (the customer may now get it)
 *   close     approved | published → closed                    publish
 *   reopen    approved | published → revision_required         approve, with the reason (a new version follows)
 *   cancel    draft | scheduled | in_progress | revision_required → cancelled   update, with the reason
 *   revise    a work order the customer signed (locked) → a revision -R1 … to correct; the signed one
 *             is cancelled                                    approve, with the reason (CreatePmRevision)
 *
 * Once the customer signed (locked), return, reopen and cancel are no longer allowed: the results stay
 * as signed and a correction is a revision. Without an approval step (PmSettings) work reaching
 * pending_approval is approved at once (MovePmVisit::approveBySystem).
 */
class PmWorkflow
{
    public const ACTIONS = [
        'schedule' => ['from' => [V::STATUS_DRAFT], 'to' => V::STATUS_SCHEDULED, 'ability' => 'update'],
        'start' => ['from' => [V::STATUS_SCHEDULED], 'to' => V::STATUS_IN_PROGRESS, 'ability' => 'perform'],
        'submit' => ['from' => V::WORKING_STATUSES, 'to' => V::STATUS_SUBMITTED, 'ability' => 'perform'],
        'review' => ['from' => [V::STATUS_SUBMITTED], 'to' => V::STATUS_PENDING_APPROVAL, 'ability' => 'review'],
        'return' => ['from' => [V::STATUS_SUBMITTED, V::STATUS_PENDING_APPROVAL], 'to' => V::STATUS_REVISION_REQUIRED, 'ability' => 'returnWork'],
        'approve' => ['from' => [V::STATUS_PENDING_APPROVAL], 'to' => V::STATUS_APPROVED, 'ability' => 'approve'],
        'publish' => ['from' => [V::STATUS_APPROVED], 'to' => V::STATUS_PUBLISHED, 'ability' => 'publish'],
        'close' => ['from' => [V::STATUS_APPROVED, V::STATUS_PUBLISHED], 'to' => V::STATUS_CLOSED, 'ability' => 'publish'],
        'reopen' => ['from' => [V::STATUS_APPROVED, V::STATUS_PUBLISHED], 'to' => V::STATUS_REVISION_REQUIRED, 'ability' => 'approve'],
        'cancel' => ['from' => V::OPEN_STATUSES, 'to' => V::STATUS_CANCELLED, 'ability' => 'cancel'],
        'revise' => ['from' => [V::STATUS_SUBMITTED, V::STATUS_PENDING_APPROVAL, V::STATUS_APPROVED, V::STATUS_PUBLISHED, V::STATUS_CLOSED],
            'to' => V::STATUS_CANCELLED, 'ability' => 'approve'],
    ];

    /** Not allowed once the customer signed. */
    public const NOT_WHEN_LOCKED = ['return', 'reopen', 'cancel'];

    /** Moves that need a reason. */
    public const NEEDS_NOTE = ['return', 'reopen', 'cancel', 'revise'];

    /** Moves made by their own action (they do more than change the status). */
    public const OWN_ACTION = ['start' => 'StartPmVisit', 'submit' => 'SubmitPmVisit', 'revise' => 'CreatePmRevision'];

    public static function allows(V $visit, string $action): bool
    {
        if (! isset(self::ACTIONS[$action]) || ! in_array($visit->status, self::ACTIONS[$action]['from'], true)) {
            return false;
        }

        return $visit->isLocked() ? ! in_array($action, self::NOT_WHEN_LOCKED, true) : $action !== 'revise';
    }

    /** Where a move leads for this company: a submit skips review when the company has none. */
    public static function target(string $action, PmSettings $settings): string
    {
        return $action === 'submit' && ! $settings->reviewStep ? V::STATUS_PENDING_APPROVAL : self::ACTIONS[$action]['to'];
    }

    /**
     * The moves the work order allows now (by status only; the policy says who).
     *
     * @return list<string>
     */
    public static function available(V $visit): array
    {
        return array_values(array_filter(array_keys(self::ACTIONS), fn (string $action) => self::allows($visit, $action)));
    }
}
