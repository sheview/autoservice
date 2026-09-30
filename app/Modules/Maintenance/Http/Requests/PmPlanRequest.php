<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Maintenance\Models\PmPlan;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PmPlanRequest extends FormRequest
{
    /** Who can be given PM rounds. */
    public const ASSIGNABLE_PERMISSION = 'pm.perform';

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

    public function attributes(): array
    {
        return __('maintenance.fields');
    }

    public function messages(): array
    {
        return ['contract_id.unique' => __('maintenance.plans.contract_has_plan')];
    }
}
