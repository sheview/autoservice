<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A PM template draft: its details, fields (ChecklistItems) and what it is tied to. The code is
 * given once, on creating; categories, brands and models must be rows of the current tenant.
 * "publish" publishes the draft right after saving it.
 */
class PmChecklistRequest extends FormRequest
{
    public const MAX_ITEMS = ChecklistItems::MAX_ITEMS;

    public const MAX_ASSIGNMENTS = 50;

    public function authorize(): bool
    {
        $checklist = $this->route('checklist');

        return $checklist instanceof PmChecklist
            ? $this->user()->can('update', $checklist)
            : $this->user()->can('create', PmChecklist::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $creating = ! $this->route('checklist') instanceof PmChecklist;
        $number = ['nullable', 'numeric', 'between:-999999,999999'];

        return [
            'code' => $creating
                ? ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('pm_checklists', 'code')->whereNull('deleted_at')]
                : ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'service_line' => ['nullable', Rule::in(PmChecklist::SERVICE_LINES)],
            'layout' => ['required', Rule::in(PmChecklist::LAYOUTS)],
            'blocks' => ['nullable', 'array'],
            'blocks.*' => [Rule::in(PmChecklist::BLOCKS)],
            'publish' => ['boolean'],

            'assignments' => ['nullable', 'array', 'max:'.self::MAX_ASSIGNMENTS],
            'assignments.*.asset_category_id' => ['nullable', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'assignments.*.asset_brand_id' => ['nullable', 'integer', Rule::exists('asset_brands', 'id')->whereNull('deleted_at')],
            'assignments.*.asset_model_id' => ['nullable', 'integer', Rule::exists('asset_models', 'id')->whereNull('deleted_at')],

            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.key' => ['required', 'string', 'regex:'.ChecklistItems::KEY_PATTERN, 'distinct'],
            'items.*.label' => ['required', 'string', 'max:200'],
            'items.*.type' => ['required', Rule::in(ChecklistItems::TYPES)],
            'items.*.section' => ['nullable', 'string', 'max:100'],
            'items.*.required' => ['boolean'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.min' => $number,
            'items.*.max' => $number,
            'items.*.options' => ['nullable', 'array', 'max:'.ChecklistItems::MAX_OPTIONS],
            'items.*.options.*' => ['nullable', 'string', 'max:100'],
            'items.*.source' => ['nullable', Rule::in(ChecklistItems::ASSET_SOURCES)],
            'items.*.photos' => ['nullable', 'integer', 'between:1,10'],
            // measure_table: rows and columns; repeat_table: columns, and rows {count} or {from, max}
            'items.*.rows' => ['nullable', 'array', 'max:'.ChecklistItems::MAX_ROWS],
            'items.*.columns' => ['nullable', 'array', 'max:'.ChecklistItems::MAX_COLUMNS],
            'items.*.columns.*.label' => ['nullable', 'string', 'max:100'],
            'items.*.columns.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.columns.*.min' => $number,
            'items.*.columns.*.max' => $number,
        ];
    }

    public function attributes(): array
    {
        return __('maintenance.fields');
    }

    /**
     * How the fields fit together (a table's rows and columns, a repeat count's field, ranges).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                foreach (ChecklistItems::schemaErrors(ChecklistItems::clean($this->input('items', []))) as $error) {
                    $validator->errors()->add('items', $error);
                }
            },
        ];
    }

    /**
     * @return array<string, mixed> for SaveTemplateDraft
     */
    public function templateData(): array
    {
        return collect($this->validated())->except('publish')->all();
    }
}
