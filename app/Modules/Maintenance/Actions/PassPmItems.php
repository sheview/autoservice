<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "ผ่านทั้งหมด" on the table of a work order (or on one set: a PC with its monitor): the equipment
 * given that has no result yet is recorded as fine, its unanswered Pass/Fail questions as Pass
 * (what was typed or autosaved stays). Equipment already recorded is left as it is; equipment that
 * still misses a required answer, or failed a question, is skipped for the technician to finish.
 * Each goes through RecordPmItem, the same as saving it by hand.
 */
class PassPmItems
{
    public function __construct(private RecordPmItem $record) {}

    /**
     * @param  list<int>  $itemIds  items of the work order
     * @return array{passed: list<int>, skipped: list<int>} item ids
     */
    public function handle(PmVisit $visit, User $actor, array $itemIds): array
    {
        if (! $visit->isWorking()) {
            throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
        }

        $items = $visit->items()->whereIn('id', $itemIds)->where('result', PmVisitItem::RESULT_PENDING)->orderBy('id')->get();
        $passed = [];
        $skipped = [];

        DB::transaction(function () use ($items, $visit, $actor, &$passed, &$skipped) {
            foreach ($items as $item) {
                $item->setRelation('visit', $visit);
                $checklist = $item->checklist ?? [];
                $answers = [...($item->answers ?? []), ...($item->draft['answers'] ?? [])];
                foreach ($checklist as $field) {
                    if ($field['type'] === 'pass_fail' && ! in_array($answers[$field['key']] ?? null, ChecklistItems::PASS_FAIL, true)) {
                        $answers[$field['key']] = 'pass';
                    }
                }

                try {
                    $this->record->handle($item, $actor, [
                        'result' => PmVisitItem::RESULT_PASSED,
                        'answers' => $answers,
                        'remarks' => $item->draft['remarks'] ?? $item->remarks ?? [],
                        'note' => $item->draft['note'] ?? $item->note,
                        'instrument_id' => $item->draft['instrument_id'] ?? $item->instrument_id,
                    ]);
                    $passed[] = $item->id;
                } catch (ValidationException) {
                    $skipped[] = $item->id;
                }
            }
        });

        return ['passed' => $passed, 'skipped' => $skipped];
    }
}
