<?php

namespace App\Modules\Contract\Http\Requests;

use App\Modules\Contract\Models\Contract;
use App\Modules\Platform\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractRequest extends FormRequest
{
    /** Longest SLA time that can be entered (30 days). */
    public const MAX_SLA_HOURS = 720;

    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof Contract
            ? $this->user()->can('update', $contract)
            : $this->user()->can('create', Contract::class);
    }

    /**
     * Customer and contract number rules only see rows of the current tenant (RLS + tenant scope).
     * SLA times are entered in hours (0.25 steps); a priority left empty has no SLA.
     */
    public function rules(): array
    {
        $contract = $this->route('contract');
        $rules = [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            // Unique including deleted contracts.
            'contract_no' => ['required', 'string', 'max:50', Rule::unique('contracts', 'contract_no')->ignore($contract?->id)],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(Contract::STATUSES)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'], // baht
            'service_window' => ['required', Rule::in(Contract::SERVICE_WINDOWS)],
            'pm_interval_months' => ['nullable', 'integer', Rule::in(Contract::PM_INTERVALS)],
            'notify_days_before' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'slas' => ['array:'.implode(',', Contract::PRIORITIES)],
        ];

        foreach (Contract::PRIORITIES as $priority) {
            $prefix = "slas.{$priority}";
            $rules["{$prefix}.response_hours"] = ["required_with:{$prefix}.resolve_hours", 'nullable', 'numeric', 'min:0.25', 'max:'.self::MAX_SLA_HOURS];
            $rules["{$prefix}.resolve_hours"] = ["required_with:{$prefix}.response_hours", 'nullable', 'numeric', "gte:{$prefix}.response_hours", 'max:'.self::MAX_SLA_HOURS];
        }

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = __('contract.fields');
        foreach (Contract::PRIORITIES as $priority) {
            $label = __("contract.priorities.{$priority}");
            $attributes["slas.{$priority}.response_hours"] = __('contract.fields.response_hours')." ({$label})";
            $attributes["slas.{$priority}.resolve_hours"] = __('contract.fields.resolve_hours')." ({$label})";
        }

        return $attributes;
    }

    /**
     * Validated data for SaveContract: value in satang, SLA in minutes, empty priorities dropped.
     *
     * @return array<string, mixed>
     */
    public function contractData(): array
    {
        $data = $this->validated();
        $data['value'] = Money::toSatang($data['value'] ?? null);

        $slas = [];
        foreach ($data['slas'] ?? [] as $priority => $sla) {
            if (($sla['response_hours'] ?? null) === null) {
                continue;
            }
            $slas[$priority] = [
                'response_minutes' => (int) round($sla['response_hours'] * 60),
                'resolve_minutes' => (int) round($sla['resolve_hours'] * 60),
            ];
        }
        $data['slas'] = $slas;

        return $data;
    }
}
