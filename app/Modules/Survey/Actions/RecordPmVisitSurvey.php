<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Survey\Models\TicketSurvey;
use Illuminate\Support\Str;

/**
 * Called by the Maintenance module when the customer signs a PM work order: the satisfaction they
 * ticked (four levels as on the paper form, kept as scores of the ticket survey scale, PM_LEVELS)
 * is the work order's survey, answered there and then. Signing again (a revision) answers the
 * survey of that work order, not the earlier one.
 */
class RecordPmVisitSurvey
{
    /**
     * @param  array{id: int, ulid: string, visit_no: string, title: string|null, customer_id: int|null, assignee_id: int|null}  $visit
     * @param  string  $level  a key of TicketSurvey::PM_LEVELS
     */
    public function handle(array $visit, string $level, ?string $comment, string $answeredName, ?int $answeredBy = null): TicketSurvey
    {
        $survey = TicketSurvey::query()->firstOrNew(['pm_visit_id' => $visit['id']], [
            'ticket_ulid' => $visit['ulid'],
            'ticket_no' => $visit['visit_no'],
            'ticket_title' => $visit['title'] ?? $visit['visit_no'],
            'customer_id' => $visit['customer_id'],
            'assignee_id' => $visit['assignee_id'],
            'token' => Str::random(40),
        ]);
        $survey->fill([
            'score' => TicketSurvey::PM_LEVELS[$level],
            'comment' => filled($comment) ? trim($comment) : null,
            'answered_at' => now(),
            'answered_by' => $answeredBy,
            'answered_name' => $answeredName,
        ])->save();

        return $survey;
    }
}
