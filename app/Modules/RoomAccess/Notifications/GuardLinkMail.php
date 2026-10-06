<?php

namespace App\Modules\RoomAccess\Notifications;

use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Support\RoomSchedule;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail to a guard / caretaker of the room (from the room's list): who is coming when, and the
 * link to record them going in and out. Names only: never phones or ID numbers.
 */
class GuardLinkMail extends Notification
{
    public function __construct(public RoomAccessRequest $request, public string $link, public string $company) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['room', 'people']);
        $replace = [
            'no' => $request->request_no,
            'room' => $request->room?->name ?? '-',
            'company' => $this->company,
            'when' => $request->planned_start->format('d/m/Y H:i').' - '.$request->planned_end->format('d/m/Y H:i')
                .($request->isRecurring() ? ' ('.__('ui.room_requests.schedule_every', ['schedule' => RoomSchedule::describe($request)]).')' : ''),
            'people' => $request->people->pluck('name')->implode(', '),
        ];

        return (new MailMessage)
            ->subject(__('room_access.guard_mail.subject', $replace))
            ->line(__('room_access.guard_mail.line', $replace))
            ->line(__('room_access.guard_mail.people', $replace))
            ->action(__('room_access.guard_mail.action'), $this->link)
            ->line(__('room_access.guard_mail.footer'));
    }
}
