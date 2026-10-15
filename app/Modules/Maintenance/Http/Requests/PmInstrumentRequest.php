<?php

namespace App\Modules\Maintenance\Http\Requests;

use App\Modules\Maintenance\Models\PmInstrument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A measuring instrument: its serial number is unique among the company's live instruments.
 */
class PmInstrumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->customer_id === null && $this->user()->can('pm-settings.manage');
    }

    public function rules(): array
    {
        $instrument = $this->route('instrument');

        return [
            'name' => ['required', 'string', 'max:255'],
            'serial_no' => ['required', 'string', 'max:100',
                Rule::unique('pm_instruments', 'serial_no')->whereNull('deleted_at')->ignore($instrument instanceof PmInstrument ? $instrument->id : null)],
            'calibration_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return __('maintenance.fields');
    }
}
