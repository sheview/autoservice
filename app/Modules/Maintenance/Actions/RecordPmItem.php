<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Validation\ValidationException;

/**
 * Records the result of one asset in a running round. Only the keys of the item's checklist are
 * kept; checks are stored as true/false and readings as numbers.
 */
class RecordPmItem
{
    /**
     * @param  array{result: string, answers?: array<string, mixed>, note?: string|null}  $data  validated
     */
    public function handle(PmVisitItem $item, User $actor, array $data): PmVisitItem
    {
        if ($item->visit->status !== PmVisit::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
        }

        $answers = [];
        foreach ($item->checklist as $field) {
            $value = $data['answers'][$field['key']] ?? null;
            $answers[$field['key']] = match ($field['type']) {
                'check' => (bool) $value,
                'number' => $value === null || $value === '' ? null : (float) $value,
                default => $value === null ? null : trim((string) $value),
            };
        }

        $item->update([
            'result' => $data['result'],
            'answers' => $answers,
            'note' => $data['note'] ?? null,
            'checked_by' => $actor->id,
            'checked_at' => now(),
        ]);

        return $item;
    }
}
