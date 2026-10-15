<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Keeps what the technician typed so far (autosave), without judging it: required fields and
 * readings out of range are checked when the item is saved (RecordPmItem). The recorded result
 * does not change.
 */
class SavePmItemDraft
{
    /**
     * @param  array{result?: string|null, answers?: array<string, mixed>, remarks?: array<string, string|null>, note?: string|null,
     *     not_inspected_reason?: string|null, instrument_id?: int|null}  $data  validated
     */
    public function handle(PmVisitItem $item, array $data): Carbon
    {
        if (! $item->visit->isWorking()) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
        }

        $savedAt = now();
        $item->update(['draft' => [
            'result' => $data['result'] ?? null,
            'answers' => $data['answers'] ?? [],
            'remarks' => array_filter($data['remarks'] ?? [], 'filled'),
            'note' => $data['note'] ?? null,
            'not_inspected_reason' => $data['not_inspected_reason'] ?? null,
            'instrument_id' => $data['instrument_id'] ?? null,
            'saved_at' => $savedAt->toIso8601String(),
        ]]);

        return $savedAt;
    }
}
