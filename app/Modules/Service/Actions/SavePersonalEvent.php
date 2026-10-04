<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\PersonalEvent;

/**
 * Adds or changes an appointment on the user's own calendar.
 */
class SavePersonalEvent
{
    /**
     * @param  array{title: string, starts_at: string, ends_at?: string|null, all_day: bool, notes?: string|null}  $data  validated
     */
    public function handle(User $user, ?PersonalEvent $event, array $data): PersonalEvent
    {
        $event ??= new PersonalEvent(['user_id' => $user->id]);
        $event->fill([
            'title' => $data['title'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'all_day' => $data['all_day'],
            'notes' => $data['notes'] ?? null,
        ])->save();

        return $event;
    }
}
