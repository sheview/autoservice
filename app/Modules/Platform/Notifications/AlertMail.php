<?php

namespace App\Modules\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An alert (AlertSender) as an e-mail to the addresses the company set.
 */
class AlertMail extends Notification
{
    public function __construct(public string $title, public string $body, public ?string $url = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title);
        foreach (preg_split('/\R/', $this->body) as $line) {
            $mail->line($line);
        }

        return $this->url ? $mail->action(__('alerts.open'), $this->url) : $mail;
    }
}
