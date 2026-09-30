<?php

namespace App\Modules\Inventory\Notifications;

use App\Modules\Inventory\Models\Part;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Daily e-mail: parts at or below their reorder point.
 * Sent synchronously from NotifyLowStockJob (which already runs in the tenant).
 */
class LowStockNotification extends Notification
{
    /**
     * @param  Collection<int, Part>  $parts
     */
    public function __construct(public Collection $parts) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('inventory.low_stock_mail.subject', ['count' => $this->parts->count()]))
            ->greeting(__('inventory.low_stock_mail.greeting', ['name' => $notifiable->name]))
            ->line(__('inventory.low_stock_mail.intro'));

        foreach ($this->parts as $part) {
            $mail->line(__($part->qty_on_hand === 0 ? 'inventory.low_stock_mail.item_out' : 'inventory.low_stock_mail.item', [
                'code' => $part->code,
                'name' => $part->name,
                'qty' => $part->qty_on_hand,
                'unit' => $part->unit,
                'min' => $part->min_qty,
            ]));
        }

        // Lowest stock first.
        return $mail->action(__('inventory.low_stock_mail.action'), route('inventory.parts.index', ['sort' => 'qty_on_hand']));
    }
}
