<?php

namespace App\Modules\Survey\Jobs;

use App\Modules\Identity\Actions\FindUsers;
use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Survey\Notifications\SurveyInvitation;
use App\Modules\Survey\Support\SurveyLink;
use App\Modules\Tenancy\Concerns\InteractsWithTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * E-mails the survey link of a closed ticket to a user of the tenant the job was dispatched in.
 */
class SendSurveyInvitation implements ShouldQueue
{
    use Dispatchable, InteractsWithTenant, Queueable;

    public function __construct(
        public int $surveyId,
        public int $userId,
    ) {}

    public function handle(FindUsers $findUsers, SurveyLink $link): void
    {
        $survey = TicketSurvey::find($this->surveyId);
        $user = $findUsers->handle([$this->userId])->first();

        if ($survey !== null && ! $survey->isAnswered() && $user !== null) {
            Notification::sendNow($user, new SurveyInvitation($survey, $link->for($survey)));
        }
    }
}
