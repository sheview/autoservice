<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
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
    public const PERMISSION = 'surveys.respond';

    /**
     * Whoever may see the ticket and may answer surveys within their scope: a customer account,
     * or staff on its behalf.
     */
    public static function allows(User $user, Ticket $ticket): bool
    {
        return $user->can('view', $ticket) && self::reaches($user, $ticket, self::PERMISSION);
    }

    /** Whoever sees surveys (surveys.view) of this ticket's survey, within their scope. */
    public static function allowsView(User $user, Ticket $ticket): bool
    {
        return self::reaches($user, $ticket, 'surveys.view');
    }

    /**
     * The survey of the ticket is within the user's scope of a surveys.* permission: surveys have
     * no branch; customer = the ticket's customer; own = the job was assigned to the user.
     */
    private static function reaches(User $user, Ticket $ticket, string $permission): bool
    {
        return $user->checkPermissionTo($permission) && DataScope::covers($ticket, $user, $permission, branch: null,
            own: fn (Ticket $t) => $t->assignee_id !== null && (int) $t->assignee_id === (int) $user->id);
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

    /**
     * Staff who work on tickets (technician, helpdesk) key in the score the customer ticked on the
     * printed job sheet, when the customer would not answer in the system.
     */
    public static function allowsPaper(User $user, Ticket $ticket): bool
    {
        return $user->customer_id === null && $user->can('update', $ticket);
    }

    public function paper(Request $request, Ticket $ticket, AnswerSurveyOfTicket $answerSurvey): RedirectResponse
    {
        abort_unless(self::allowsPaper($request->user(), $ticket), 403);

        $answer = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'name' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], attributes: [...__('survey.fields'), 'name' => __('service.fields.rater_name')]);

        $answerSurvey->handle($ticket->id, [...$answer, 'on_paper' => true], $request->user());

        return back()->with('success', __('service.tickets.paper_rated'));
    }
}
