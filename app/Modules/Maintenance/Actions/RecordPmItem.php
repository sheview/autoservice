<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Validation\ValidationException;

/**
 * Records the result of one piece of equipment while the work is going on (or sent back for
 * revision). Only the keys of the item's checklist are kept, each as its type stores it
 * (ChecklistItems), with a note per question. Unless it was skipped, required questions must be
 * answered, and equipment with a failed question (a Fail, or a reading out of range) cannot be
 * recorded as fine.
 */
class RecordPmItem
{
    public function __construct(private AssetDetails $assetDetails) {}

    /**
     * @param  array{result: string, answers?: array<string, mixed>, remarks?: array<string, string|null>, note?: string|null}  $data  validated
     */
    public function handle(PmVisitItem $item, User $actor, array $data): PmVisitItem
    {
        if (! $item->visit->isWorking()) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
        }

        $checklist = $item->checklist ?? [];
        $asset = $this->assetDetails->handle([$item->asset_id])[$item->asset_id] ?? [];
        $answers = ChecklistItems::normalize($checklist, $data['answers'] ?? [], $asset);

        if ($data['result'] !== PmVisitItem::RESULT_NOT_INSPECTED) {
            if ($missing = ChecklistItems::missing($checklist, $answers)) {
                throw ValidationException::withMessages(['answers' => __('maintenance.visits.answers_missing', ['items' => implode(', ', $missing)])]);
            }
            if ($data['result'] === PmVisitItem::RESULT_PASSED && ($failed = ChecklistItems::failures($checklist, $answers))) {
                throw ValidationException::withMessages(['result' => __('maintenance.visits.failed_not_ok', ['items' => implode(', ', $failed)])]);
            }
        }

        $keys = array_column($checklist, 'key');
        $remarks = array_filter(
            array_map(fn ($text) => filled($text) ? trim((string) $text) : null, array_intersect_key($data['remarks'] ?? [], array_flip($keys))),
        );

        $item->update([
            'result' => $data['result'],
            'answers' => $answers,
            'remarks' => $remarks ?: null,
            'note' => $data['note'] ?? null,
            'checked_by' => $actor->id,
            'checked_at' => now(),
        ]);

        return $item;
    }
}
