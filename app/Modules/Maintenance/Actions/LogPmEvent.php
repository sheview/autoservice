<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;

/**
 * Writes one line of a work order's lasting history (pm_visit_events). The actor is kept by name
 * too: users may be deleted, the history stays readable. A null actor is the system, or a
 * customer through a share link (say who in $data).
 */
class LogPmEvent
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function handle(PmVisit $visit, string $type, ?User $actor, ?string $note = null, ?string $from = null, ?string $to = null, ?array $data = null): PmVisitEvent
    {
        return $visit->events()->create([
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'note' => filled($note) ? $note : null,
            'data' => $data,
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'created_at' => now(),
        ]);
    }
}
