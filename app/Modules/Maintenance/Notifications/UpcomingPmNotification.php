<?php

namespace App\Modules\Maintenance\Notifications;

use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Daily e-mail: PM rounds coming up (or late) that have not started.
 * Sent synchronously from NotifyUpcomingPmJob (which already runs in the tenant).
 */
class UpcomingPmNotification extends Notification
{
    /**
     * @param  Collection<int, PmVisit>  $visits
     * @param  array<int, string>  $customers  customer id => name
     * @param  bool  $forManagers  the rounds have no technician yet
     */
    public function __construct(public Collection $visits, public array $customers, public bool $forManagers) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = $this->forManagers ? 'unassigned' : 'assigned';
        $mail = (new MailMessage)
            ->subject(__("maintenance.mail.{$key}.subject", ['count' => $this->visits->count()]))
            ->greeting(__('maintenance.mail.greeting', ['name' => $notifiable->name]))
            ->line(__("maintenance.mail.{$key}.line"));

        foreach ($this->visits as $visit) {
            $date = $visit->scheduled_on ?? $visit->due_on;
            $mail->line(__('maintenance.mail.item', [
                'no' => $visit->visit_no,
                'plan' => $visit->plan?->title,
                'customer' => $this->customers[$visit->customer_id] ?? '-',
                // E-mails are documents for people outside the system: Buddhist year.
                'date' => $date->copy()->addYears(543)->format('d/m/Y'),
                'kind' => __($visit->scheduled_on ? 'maintenance.mail.appointment' : 'maintenance.mail.due'),
            ]));
        }

        return $mail->action(__('maintenance.mail.action'), route('maintenance.visits.index', $this->forManagers ? ['assignee' => 'none'] : ['assignee' => 'me']));
    }
}
