<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Part;
use App\Modules\Platform\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PartRequest extends FormRequest
{
    public function authorize(): bool
    {
        $part = $this->route('part');

        return $part instanceof Part
            ? $this->user()->can('update', $part)
            : $this->user()->can('create', Part::class);
    }

    public function rules(): array
    {
        $part = $this->route('part');

        return [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/', function (string $attribute, mixed $value, Closure $fail) use ($part) {
                // Unique per tenant, ignoring case (the tenant scope limits the query to this tenant).
                $taken = Part::query()
                    ->whereRaw('lower(code) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($part, fn ($q) => $q->whereKeyNot($part->id))
                    ->exists();
                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => __('inventory.fields.code')]));
                }
            }],
            'name' => ['required', 'string', 'max:255'],
            // The MA contract (project) the part is kept for.
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->whereNull('deleted_at')],
            'part_category_id' => ['nullable', 'integer', Rule::exists('part_categories', 'id')->whereNull('deleted_at')],
            // A new part only (an existing one is switched on its page), and only with parts.serials;
            // otherwise a new part takes its category's setting.
            'track_serial' => ['nullable', 'boolean'],
            'part_number' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:30'],
            'min_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'], // baht
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return __('inventory.fields');
    }

    /**
     * Validated data for SavePart: unit cost in satang.
     *
     * @return array<string, mixed>
     */
    public function partData(): array
    {
        $data = $this->validated();
        $data['unit_cost'] = Money::toSatang($data['unit_cost'] ?? null);
        if ($this->route('part') instanceof Part || ! $this->user()->can('parts.serials')) {
            unset($data['track_serial']);
        }

        return $data;
    }
}
