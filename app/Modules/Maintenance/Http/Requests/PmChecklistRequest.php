<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Modules\Maintenance\Models\PmChecklist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PmChecklistRequest extends FormRequest
{
    public const MAX_ITEMS = 50;

    public function authorize(): bool
    {
        $checklist = $this->route('checklist');

        return $checklist instanceof PmChecklist
            ? $this->user()->can('update', $checklist)
            : $this->user()->can('create', PmChecklist::class);
    }

    /**
     * One checklist per asset category, and one general checklist (no category).
     * The category must be a row of the current tenant (RLS + tenant scope).
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'asset_category_id' => ['nullable', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'items.*.label' => ['required', 'string', 'max:200'],
            'items.*.type' => ['required', Rule::in(PmChecklist::ITEM_TYPES)],
        ];
    }

    public function attributes(): array
    {
        return __('maintenance.fields');
    }

    /**
     * Checked here rather than in rules(), which skip an empty category (the general checklist).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('asset_category_id')) {
                    return;
                }

                $checklist = $this->route('checklist');
                $categoryId = $this->integer('asset_category_id') ?: null;
                $taken = PmChecklist::query()
                    ->when($categoryId, fn ($q, $id) => $q->where('asset_category_id', $id), fn ($q) => $q->whereNull('asset_category_id'))
                    ->when($checklist, fn ($q) => $q->whereKeyNot($checklist->id))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('asset_category_id', __($categoryId ? 'maintenance.checklists.category_taken' : 'maintenance.checklists.general_taken'));
                }
            },
        ];
    }
}
