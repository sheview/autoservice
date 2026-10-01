<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Survey\Models\TicketSurvey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the answer of a survey. A survey is answered once: the row is locked, so the public
 * link opened twice cannot both answer.
 */
class AnswerTicketSurvey
{
    /**
     * @param  array{score: int, comment?: string|null, name?: string|null, on_paper?: bool}  $answer
     *                                                                                                 on_paper = the customer ticked the printed job sheet and $user keyed it in; name = who rated
     * @param  User|null  $user  null = answered through the public link
     */
    public function handle(TicketSurvey $survey, array $answer, ?User $user = null): TicketSurvey
    {
        return DB::transaction(function () use ($survey, $answer, $user) {
            $locked = TicketSurvey::query()->lockForUpdate()->findOrFail($survey->id);

            if ($locked->isAnswered()) {
                throw ValidationException::withMessages(['score' => __('survey.already_answered')]);
            }

            $onPaper = (bool) ($answer['on_paper'] ?? false);
            $typedName = filled($answer['name'] ?? null) ? trim($answer['name']) : null;

            $locked->update([
                'score' => (int) $answer['score'],
                'comment' => filled($answer['comment'] ?? null) ? trim($answer['comment']) : null,
                'answered_at' => now(),
                'answered_by' => $user?->id,
                'answered_name' => $onPaper ? $typedName : ($user?->name ?? $typedName),
                'on_paper' => $onPaper,
            ]);

            return $locked;
        });
    }
}
