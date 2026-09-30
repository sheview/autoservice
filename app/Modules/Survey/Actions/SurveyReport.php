<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Survey\Models\TicketSurvey;
use Carbon\CarbonInterface;

/**
 * Satisfaction figures of the current tenant for the surveys sent in a period, as plain arrays,
 * for the Reporting module (which must not use the TicketSurvey model directly).
 */
class SurveyReport
{
    public function __construct(private SummariseTicketSurveys $summarise) {}

    /**
     * @return array{sent: int, answered: int, response_rate: int|null, average: float|null, scores: array<int, int>,
     *     by_assignee: array<int, array{answers: int, average: float}>}
     */
    public function handle(CarbonInterface $from, CarbonInterface $to): array
    {
        $sent = fn () => TicketSurvey::query()->whereBetween('created_at', [$from, $to]);

        return [
            ...$this->summarise->handle($sent()),
            'by_assignee' => $sent()->whereNotNull('assignee_id')->whereNotNull('score')
                ->groupBy('assignee_id')
                ->selectRaw('assignee_id, count(*) as answers, avg(score) as average')
                ->get()
                ->mapWithKeys(fn (TicketSurvey $row) => [$row->assignee_id => [
                    'answers' => (int) $row->getAttribute('answers'),
                    'average' => round((float) $row->getAttribute('average'), 2),
                ]])->all(),
        ];
    }
}
