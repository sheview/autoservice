<?php

namespace App\Modules\Contract\Notifications;

use App\Modules\Contract\Models\Contract;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Daily e-mail: contracts of the tenant that are about to expire.
 * Sent synchronously from NotifyExpiringContractsJob (which already runs in the tenant).
 */
class ContractsExpiringNotification extends Notification
{
    /**
     * @param  Collection<int, Contract>  $contracts
     */
    public function __construct(public Collection $contracts, public string $tenantName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('contract.expiring_mail.subject', ['count' => $this->contracts->count(), 'tenant' => $this->tenantName]))
            ->greeting(__('contract.expiring_mail.greeting', ['name' => $notifiable->name]))
            ->line(__('contract.expiring_mail.intro'));

        foreach ($this->contracts as $contract) {
            $mail->line(__('contract.expiring_mail.item', [
                'no' => $contract->contract_no,
                'title' => $contract->title,
                'customer' => $contract->customer?->name,
                // E-mails are documents for people outside the system: Buddhist year.
                'date' => $contract->ends_on->copy()->addYears(543)->format('d/m/Y'),
                'days' => (int) now()->startOfDay()->diffInDays($contract->ends_on, false),
            ]));
        }

        return $mail->action(__('contract.expiring_mail.action'), route('contract.contracts.index', ['phase' => 'expiring']));
    }
}
