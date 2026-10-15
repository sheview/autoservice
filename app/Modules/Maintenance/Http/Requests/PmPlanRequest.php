<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Maintenance\Models\PmPlan;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PmPlanRequest extends FormRequest
{
    /** Who can be given PM rounds. */
    public const ASSIGNABLE_PERMISSION = 'pm-visits.complete';

    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof PmPlan
            ? $this->user()->can('update', $plan)
            : $this->user()->can('create', PmPlan::class);
    }

    /**
     * The contract (only when creating) must be an active contract of the current tenant that has
     * not ended and has no plan yet. The assignee must be staff who may perform PM.
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'interval_months' => ['required', 'integer', Rule::in(PmPlan::INTERVALS)],
            'assignee_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! app(UsersWithPermission::class)->handle(self::ASSIGNABLE_PERMISSION)->contains('id', (int) $value)) {
                    $fail(__('validation.exists', ['attribute' => __('maintenance.fields.assignee_id')]));
                }
            }],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['boolean'],
            // The plan's period: the contract's unless given, and within it (checked in after()).
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            // The equipment covered: assets of the contract (checked in after()); none = all of them.
            'asset_ids' => ['nullable', 'array', 'max:2000'],
            'asset_ids.*' => ['integer', 'distinct'],
        ];

        if (! $this->route('plan') instanceof PmPlan) {
            $rules['contract_id'] = [
                'required', 'integer',
                Rule::exists('contracts', 'id')->whereNull('deleted_at')->where('status', 'active')
                    ->where(fn ($q) => $q->where('ends_on', '>=', today()->toDateString())),
                Rule::unique('pm_plans', 'contract_id')->whereNull('deleted_at'),
            ];
        }

        return $rules;
    }

    /**
     * The period within the contract's, and only the contract's own equipment.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $plan = $this->route('plan');
            $contractId = $plan instanceof PmPlan ? $plan->contract_id : (int) $this->input('contract_id');
            $contract = app(ContractDetails::class)->handle([$contractId])[$contractId] ?? null;
            if ($contract === null) {
                return;
            }

            foreach (['starts_on', 'ends_on'] as $field) {
                $day = $this->input($field);
                if (filled($day) && ($day < $contract['starts_on'] || $day > $contract['ends_on'])) {
                    $validator->errors()->add($field, __('maintenance.plans.outside_contract', ['from' => $contract['starts_on'], 'to' => $contract['ends_on']]));
                }
            }
            if (array_diff(array_map('intval', (array) $this->input('asset_ids', [])), $contract['asset_ids']) !== []) {
                $validator->errors()->add('asset_ids', __('maintenance.plans.asset_not_in_contract'));
            }
        }];
    }

    public function attributes(): array
    {
        return __('maintenance.fields');
    }

    public function messages(): array
    {
        return ['contract_id.unique' => __('maintenance.plans.contract_has_plan')];
    }
}
