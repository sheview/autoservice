<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Survey\Models\TicketSurvey;

/**
 * The satisfaction the customer ticked on a PM work order (a key of TicketSurvey::PM_LEVELS, with
 * their comment), for the Maintenance module's sheet; null when not answered.
 */
class PmVisitSatisfaction
{
    /**
     * @return array{level: string, comment: string|null}|null
     */
    public function handle(int $visitId): ?array
    {
        $survey = TicketSurvey::query()->where('pm_visit_id', $visitId)->whereNotNull('answered_at')->first();
        $level = $survey ? array_search($survey->score, TicketSurvey::PM_LEVELS, true) : false;

        return $level === false ? null : ['level' => $level, 'comment' => $survey->comment];
    }
}
