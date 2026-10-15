<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Validation\Rule;

/**
 * What the technician has typed so far (autosave): every value must still be of its field's type,
 * but nothing is required yet — not the result, nor a note or reason for it.
 */
class PmItemDraftRequest extends RecordPmItemRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'result' => ['nullable', Rule::in(PmVisitItem::RESULTS)],
            'note' => ['nullable', 'string', 'max:5000'],
            'not_inspected_reason' => ['nullable', Rule::in(PmVisitItem::NOT_INSPECTED_REASONS)],
        ];
    }
}
