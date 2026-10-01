<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Survey\Support\SurveyLink;

/**
 * The survey of a ticket as a plain array, for the Service module's ticket page, which must not
 * use the TicketSurvey model directly. Null when the ticket has no survey (not closed yet, or
 * closed before the module was switched on).
 */
class SurveyOfTicket
{
    public function __construct(private SurveyLink $link) {}

    /**
     * @return array{answered: bool, score: int|null, comment: string|null, answered_name: string|null, on_paper: bool,
     *     answered_at: string|null, url: string}|null
     */
    public function handle(int $ticketId): ?array
    {
        $survey = TicketSurvey::query()->where('ticket_id', $ticketId)->first();

        return $survey === null ? null : [
            'answered' => $survey->isAnswered(),
            ...$survey->only(['score', 'comment', 'answered_name', 'on_paper']),
            'answered_at' => $survey->answered_at?->toIso8601String(),
            'url' => $this->link->for($survey),
        ];
    }
}
