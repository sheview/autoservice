<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\Ticket;
use App\Modules\Survey\Actions\AnswerSurveyOfTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Answering the satisfaction survey of a closed ticket from the ticket page. Surveys belong to
 * the Survey module (its actions do the work); this route also needs that module switched on.
 */
class TicketSurveyController extends Controller
{
    public const PERMISSION = 'survey.answer';

    /** Whoever may see the ticket and may answer surveys: a customer account, or staff on its behalf. */
    public static function allows(User $user, Ticket $ticket): bool
    {
        return $user->can(self::PERMISSION) && $user->can('view', $ticket);
    }

    public function store(Request $request, Ticket $ticket, AnswerSurveyOfTicket $answerSurvey): RedirectResponse
    {
        abort_unless(self::allows($request->user(), $ticket), 403);

        $answer = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], attributes: __('survey.fields'));

        $answerSurvey->handle($ticket->id, $answer, $request->user());

        return back()->with('success', __('survey.thanks'));
    }
}
