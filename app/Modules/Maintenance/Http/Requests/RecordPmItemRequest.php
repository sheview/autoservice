<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Modules\Maintenance\Models\PmVisitItem;
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
            'note' => [Rule::requiredIf($this->input('result') === PmVisitItem::RESULT_ISSUE), 'nullable', 'string', 'max:5000'],
        ];

        foreach ($item->checklist as $field) {
            $rules["answers.{$field['key']}"] = match ($field['type']) {
                'check' => ['nullable', 'boolean'],
                'number' => ['nullable', 'numeric', 'between:-999999,999999'],
                default => ['nullable', 'string', 'max:500'],
            };
        }

        return $rules;
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
