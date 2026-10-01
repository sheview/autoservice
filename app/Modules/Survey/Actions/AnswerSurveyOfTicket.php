<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Survey\Models\TicketSurvey;
use Illuminate\Validation\ValidationException;

/**
 * Answers the survey of a ticket from the ticket page. The caller (Service module) has already
 * checked that $user may see the ticket and may answer surveys.
 */
class AnswerSurveyOfTicket
{
    public function __construct(private AnswerTicketSurvey $answerSurvey) {}

    /**
     * @param  array{score: int, comment?: string|null, name?: string|null, on_paper?: bool}  $answer  see AnswerTicketSurvey
     */
    public function handle(int $ticketId, array $answer, User $user): TicketSurvey
    {
        $survey = TicketSurvey::query()->where('ticket_id', $ticketId)->first();
        if ($survey === null) {
            throw ValidationException::withMessages(['score' => __('survey.not_available')]);
        }

        return $this->answerSurvey->handle($survey, $answer, $user);
    }
}
