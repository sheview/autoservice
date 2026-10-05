<?php

namespace App\Modules\Service\Notifications;

use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Service\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail to the person who reported a problem (the e-mail they gave on the QR form): the job was
 * received, accepted, needs more from them, turned down, or done — with their tracking link and
 * what the office wrote to them. Only what a customer may see: never people, parts or notes.
 * Texts are in lang/th/service.php "customer_mail.{event}".
 */
class CustomerTicketMail extends Notification
{
    public const EVENTS = ['received', 'accepted', 'asked', 'rejected', 'done'];

    public function __construct(public Ticket $ticket, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $replace = [
            'no' => $this->ticket->ticket_no,
            'company' => $this->ticket->tenant?->name ?? config('app.name'),
        ];

        $mail = (new MailMessage)
            ->subject(__("service.customer_mail.{$this->event}.subject", $replace))
            ->greeting(__('service.customer_mail.greeting', ['name' => $this->ticket->contact_name ?? '']))
            ->line(__("service.customer_mail.{$this->event}.line", $replace));

        if (filled($this->ticket->customer_message) && in_array($this->event, ['accepted', 'asked', 'rejected'], true)) {
            $mail->line(__('service.customer_mail.message', ['message' => $this->ticket->customer_message]));
        }

        return $mail
            ->action(__('service.customer_mail.action'), PublicUrl::forTenant($this->ticket->tenant, '/track/'.$this->ticket->tracking_token))
            ->line(__('service.customer_mail.footer', $replace));
    }
}
