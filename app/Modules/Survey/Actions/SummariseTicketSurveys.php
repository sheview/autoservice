<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Survey\Models\TicketSurvey;
use Illuminate\Database\Eloquent\Builder;

/**
 * Totals of a survey query (the filtered list): how many were sent and answered, the average
 * score and how many answers each score got.
 */
class SummariseTicketSurveys
{
    /**
     * @param  Builder<TicketSurvey>  $query
     * @return array{sent: int, answered: int, response_rate: int|null, average: float|null, scores: array<int, int>}
     */
    public function handle(Builder $query): array
    {
        $byScore = (clone $query)->reorder()
            ->groupBy('score')
            ->selectRaw('score, count(*) as total')
            ->pluck('total', 'score');

        $scores = [];
        for ($score = TicketSurvey::MAX_SCORE; $score >= TicketSurvey::MIN_SCORE; $score--) {
            $scores[$score] = (int) ($byScore[$score] ?? 0);
        }

        $sent = (int) $byScore->sum();
        $answered = array_sum($scores);
        $points = 0;
        foreach ($scores as $score => $count) {
            $points += $score * $count;
        }

        return [
            'sent' => $sent,
            'answered' => $answered,
            'response_rate' => $sent === 0 ? null : (int) round($answered * 100 / $sent),
            'average' => $answered === 0 ? null : round($points / $answered, 2),
            'scores' => $scores,
        ];
    }
}
