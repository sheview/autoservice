<?php

namespace App\Modules\Maintenance\Support;

use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;

/**
 * The SHA-256 of a work order's results as they are, kept with the customer's signature: the same
 * results always give the same hash, so a later check shows whether anything changed after signing.
 * Covers each piece of equipment (by id) with its template, result, reason, answers, remarks, note
 * and instrument, as stored.
 */
final class PmDataHash
{
    public static function of(PmVisit $visit): string
    {
        $items = $visit->items()->orderBy('id')->get()->map(fn (PmVisitItem $item) => [
            'item' => $item->id,
            'asset' => $item->asset_id,
            'template' => [$item->template_code, $item->template_version],
            'result' => $item->result,
            'not_inspected_reason' => $item->not_inspected_reason,
            'answers' => $item->answers,
            'remarks' => $item->remarks,
            'note' => $item->note,
            'instrument' => $item->instrument_id,
        ])->all();

        return hash('sha256', json_encode(['visit' => $visit->id, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
