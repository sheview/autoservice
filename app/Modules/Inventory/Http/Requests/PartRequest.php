<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Part;
use App\Modules\Platform\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

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

        return $data;
    }
}
