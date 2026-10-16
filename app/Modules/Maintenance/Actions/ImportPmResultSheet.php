<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * The results sheet (ExportPmResultSheet) filled in and brought back: each row ticked in one of
 * ปกติ (fine), ผิดปกติ (found a problem) or "ไม่ได้ตรวจ" (not inspected) is recorded through
 * RecordPmItem as if saved by hand, the remark as its note (or the reason it was not inspected).
 * Pass and Fail answer the Pass/Fail questions still open as Pass (as "ผ่านทั้งหมด"); what was
 * typed before stays. Rows left blank are left as they are. A row that cannot be recorded (two
 * ticks, an answer the sheet cannot give such as a reading, a failed question on a Pass) is
 * skipped and reported, the others are saved.
 */
class ImportPmResultSheet
{
    public function __construct(private RecordPmItem $record, private AssetDetails $assetDetails) {}

    /**
     * @return array{saved: int, skipped: list<string>} the rows not recorded, "code: why"
     */
    public function handle(PmVisit $visit, User $actor, UploadedFile $file): array
    {
        if (! $visit->isWorking()) {
            throw ValidationException::withMessages(['file' => __('maintenance.visits.not_in_progress')]);
        }

        try {
            $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, true);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('maintenance.result_sheet.unreadable')]);
        }

        $items = $visit->items()->get()->keyBy('id');
        $codes = collect($this->assetDetails->handle($items->pluck('asset_id')->all()))->map(fn (array $asset) => $asset['asset_code']);
        $saved = 0;
        $skipped = [];
        $found = false;

        DB::transaction(function () use ($rows, $items, $codes, $visit, $actor, &$saved, &$skipped, &$found) {
            foreach ($rows as $number => $row) {
                $id = $row[ExportPmResultSheet::COL_ITEM] ?? null;
                if (! is_numeric($id) || ! $items->has((int) $id)) {
                    continue;
                }
                $found = true;
                /** @var PmVisitItem $item */
                $item = $items[(int) $id];
                $code = $codes[$item->asset_id] ?? "#{$number}";
                $ticked = array_keys(array_filter([
                    PmVisitItem::RESULT_PASSED => $this->ticked($row[ExportPmResultSheet::COL_PASS] ?? null),
                    PmVisitItem::RESULT_FAILED => $this->ticked($row[ExportPmResultSheet::COL_FAIL] ?? null),
                    PmVisitItem::RESULT_NOT_INSPECTED => $this->ticked($row[ExportPmResultSheet::COL_SKIP] ?? null),
                ]));
                if ($ticked === []) {
                    continue;
                }
                if (count($ticked) > 1) {
                    $skipped[] = "{$code}: ".__('maintenance.result_sheet.two_ticks');

                    continue;
                }

                $result = $ticked[0];
                $remark = filled($row[ExportPmResultSheet::COL_REMARK] ?? null) ? trim((string) $row[ExportPmResultSheet::COL_REMARK]) : null;
                $item->setRelation('visit', $visit);

                try {
                    $this->record->handle($item, $actor, [
                        'result' => $result,
                        'answers' => $result === PmVisitItem::RESULT_NOT_INSPECTED ? ($item->answers ?? []) : $this->passOpenQuestions($item),
                        'remarks' => $item->remarks ?? [],
                        ...$result === PmVisitItem::RESULT_NOT_INSPECTED
                            ? $this->whyNotInspected($remark, $item)
                            : ['note' => $remark ?? $item->note],
                        'instrument_id' => $item->instrument_id,
                    ]);
                    $saved++;
                } catch (ValidationException $e) {
                    $skipped[] = "{$code}: ".collect($e->errors())->flatten()->first();
                }
            }
        });

        if (! $found) {
            throw ValidationException::withMessages(['file' => __('maintenance.result_sheet.not_this_visit')]);
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * "เครื่องส่งซ่อม · ส่งศูนย์" → the reason it names (by its words or code) and the rest as the note;
     * anything else is "other" with the whole remark as the note.
     *
     * @return array{not_inspected_reason: string, note: string|null}
     */
    private function whyNotInspected(?string $remark, PmVisitItem $item): array
    {
        foreach (PmVisitItem::NOT_INSPECTED_REASONS as $reason) {
            foreach ([__("ui.pm_visits.not_inspected_reasons.{$reason}"), $reason] as $words) {
                if ($remark !== null && str_starts_with(mb_strtolower($remark), mb_strtolower($words))) {
                    $rest = preg_replace('/^[\s·:,\-]+|[\s·:,\-]+$/u', '', mb_substr($remark, mb_strlen($words)));

                    return ['not_inspected_reason' => $reason, 'note' => $rest !== '' ? $rest : $item->note];
                }
            }
        }

        return ['not_inspected_reason' => 'other', 'note' => $remark ?? $item->note];
    }

    /** Anything written in the box counts as a tick (✓, /, x, 1...), but "-", "0" or "no". */
    private function ticked(mixed $value): bool
    {
        $text = mb_strtolower(trim((string) $value));

        return $text !== '' && ! in_array($text, ['-', '0', 'no', 'false', 'n'], true);
    }

    /**
     * The item's answers so far (saved, else autosaved) with its open Pass/Fail questions as Pass.
     *
     * @return array<string, mixed>
     */
    private function passOpenQuestions(PmVisitItem $item): array
    {
        $answers = [...($item->answers ?? []), ...($item->draft['answers'] ?? [])];
        foreach ($item->checklist ?? [] as $field) {
            if ($field['type'] === 'pass_fail' && ! in_array($answers[$field['key']] ?? null, ChecklistItems::PASS_FAIL, true)) {
                $answers[$field['key']] = 'pass';
            }
        }

        return $answers;
    }
}
