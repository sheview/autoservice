<?php

namespace App\Modules\Service\Notifications;

use App\Modules\Service\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail about something that happened to a ticket: assigned, opened (by a customer), resolved,
 * response_breached, resolve_breached. Texts are in lang/th/service.php "mail.{event}".
 * Sent synchronously from SendTicketNotification, which already runs in the tenant.
 */
class TicketNotification extends Notification
{
    public const EVENTS = ['assigned', 'opened', 'resolved', 'response_breached', 'resolve_breached'];

    public function __construct(public Ticket $ticket, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = [
            'no' => $this->ticket->ticket_no,
            'title' => $this->ticket->title,
            'priority' => __("ui.tickets.priorities.{$this->ticket->priority}"),
        ];

        return (new MailMessage)
            ->subject(__("service.mail.{$this->event}.subject", $replace))
            ->greeting(__('service.mail.greeting', ['name' => $notifiable->name]))
            ->line(__("service.mail.{$this->event}.line", $replace))
            ->line(__('service.mail.ticket', $replace))
            ->action(__('service.mail.action'), route('service.tickets.show', $this->ticket));
    }
}
