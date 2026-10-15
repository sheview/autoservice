<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPmItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('perform', $this->route('visit'));
    }

    /**
     * Answers follow the item's own copy of the checklist.
     */
    public function rules(): array
    {
        /** @var PmVisitItem $item */
        $item = $this->route('item');
        $rules = [
            'result' => ['required', Rule::in(PmVisitItem::RESULTS)],
            'answers' => ['array'],
            'note' => [Rule::requiredIf($this->input('result') === PmVisitItem::RESULT_FAILED), 'nullable', 'string', 'max:5000'],
            // A note per question, by key.
            'remarks' => ['nullable', 'array'],
            'remarks.*' => ['nullable', 'string', 'max:1000'],
            // Why it was not inspected (details in note), and the measuring instrument used: one of the company's.
            'not_inspected_reason' => [Rule::requiredIf($this->input('result') === PmVisitItem::RESULT_NOT_INSPECTED), 'nullable', Rule::in(PmVisitItem::NOT_INSPECTED_REASONS)],
            'instrument_id' => ['nullable', 'integer', Rule::exists('pm_instruments', 'id')->whereNull('deleted_at')],
        ];

        return $rules + ChecklistItems::answerRules($item->checklist);
    }

    public function attributes(): array
    {
        /** @var PmVisitItem $item */
        $item = $this->route('item');

        return __('maintenance.fields') + collect($item->checklist)
            ->mapWithKeys(fn (array $field) => ["answers.{$field['key']}" => $field['label']])
            ->all();
    }
}
