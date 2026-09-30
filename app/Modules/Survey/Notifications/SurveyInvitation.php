<?php

namespace App\Modules\Survey\Notifications;

use App\Modules\Survey\Models\TicketSurvey;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail to the customer who reported a ticket, once it is closed: please rate the service.
 * Sent synchronously from SendSurveyInvitation, which already runs in the tenant.
 */
class SurveyInvitation extends Notification
{
    public function __construct(public TicketSurvey $survey, public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = ['no' => $this->survey->ticket_no, 'title' => $this->survey->ticket_title];

        return (new MailMessage)
            ->subject(__('survey.mail.subject', $replace))
            ->greeting(__('survey.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('survey.mail.line', $replace))
            ->action(__('survey.mail.action'), $this->url);
    }
}
