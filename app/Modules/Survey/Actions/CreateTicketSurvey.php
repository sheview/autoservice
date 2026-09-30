<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Identity\Actions\FindUsers;
use App\Modules\Identity\Models\User;
use App\Modules\Survey\Jobs\SendSurveyInvitation;
use App\Modules\Survey\Models\TicketSurvey;
use Illuminate\Support\Str;

/**
 * Called by the Service module when a ticket is closed: creates its survey (once) and invites the
 * reporter by e-mail when that is a customer account. The person who closed the ticket is not
 * e-mailed: they are looking at the ticket, where the survey is waiting.
 */
class CreateTicketSurvey
{
    public function __construct(private FindUsers $findUsers) {}

    /**
     * @param  array{id: int, ulid: string, ticket_no: string, title: string, customer_id: int|null,
     *     assignee_id: int|null, reported_by: int|null}  $ticket
     */
    public function handle(array $ticket, ?User $actor = null): TicketSurvey
    {
        $survey = TicketSurvey::withTrashed()->firstOrCreate(['ticket_id' => $ticket['id']], [
            'ticket_ulid' => $ticket['ulid'],
            'ticket_no' => $ticket['ticket_no'],
            'ticket_title' => $ticket['title'],
            'customer_id' => $ticket['customer_id'],
            'assignee_id' => $ticket['assignee_id'],
            'token' => Str::random(40),
        ]);

        if ($survey->wasRecentlyCreated && $ticket['reported_by'] !== null && $ticket['reported_by'] !== $actor?->id) {
            $reporter = $this->findUsers->handle([$ticket['reported_by']])->first();

            if ($reporter?->customer_id !== null && $reporter->is_active) {
                SendSurveyInvitation::dispatch($survey->id, $reporter->id)->afterCommit();
            }
        }

        return $survey;
    }
}
