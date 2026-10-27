<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Actions\FindDuplicateParts;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\PartSerials;
use App\Modules\Platform\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PartRequest extends FormRequest
{
    public function authorize(): bool
    {
        $part = $this->route('part');

        return $part instanceof Part
            ? $this->user()->can('update', $part)
            : $this->user()->can('create', Part::class);
    }

    /** Serial boxes left empty are not serials. */
    protected function prepareForValidation(): void
    {
        $serials = $this->input('initial_serials');
        if (is_array($serials)) {
            $this->merge(['initial_serials' => array_values(array_filter(
                array_map(fn ($serial) => is_scalar($serial) ? trim((string) $serial) : $serial, $serials),
                fn ($serial) => $serial !== '' && $serial !== null,
            ))]);
        }
    }

    public function rules(): array
    {
        $part = $this->route('part');

        return [
            // A new part without a code gets the next "PT-00001" (SavePart).
            'code' => [$part instanceof Part ? 'required' : 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/', function (string $attribute, mixed $value, Closure $fail) use ($part) {
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
            // Parts share the asset categories.
            'asset_category_id' => ['nullable', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            // Where it is kept: the storage locations shared with assets.
            'storage_location_id' => ['nullable', 'integer', Rule::exists('storage_locations', 'id')->whereNull('deleted_at')],
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
            // A new part only: its first stock, received with it (CreatePart). A count, or for a part
            // tracked by serial the serial numbers; only for whoever may enter stock movements.
            'initial_qty' => ['nullable', 'integer', 'min:0', 'max:1000000', $this->stockRule()],
            'initial_serials' => ['nullable', 'array', 'max:'.self::MAX_INITIAL_SERIALS, $this->stockRule()],
            'initial_serials.*' => ['nullable', 'string', 'max:'.PartSerials::MAX_LENGTH, 'distinct:ignore_case'],
        ];
    }

    public const MAX_INITIAL_SERIALS = 500;

    /** Initial stock: not on an existing part, and only with stock-movements.create. */
    private function stockRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $given = is_array($value) ? PartSerials::clean($value) !== [] : (int) $value > 0;
            if (! $given) {
                return;
            }
            if ($this->route('part') instanceof Part) {
                $fail(__('inventory.parts.initial_on_create'));
            } elseif (! $this->user()->can(StockMovement::permissionFor(StockMovement::TYPE_RECEIVE))) {
                $fail(__('inventory.parts.initial_forbidden'));
            }
        };
    }

    /**
     * The first stock of a new part, for CreatePart.
     *
     * @return array{quantity: int, serials: list<string>}
     */
    public function initialStock(): array
    {
        return [
            'quantity' => (int) $this->validated('initial_qty', 0),
            'serials' => PartSerials::clean($this->validated('initial_serials', [])),
        ];
    }

    /** The same part twice (part number or name, FindDuplicateParts): say which one it already is. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $part = $this->route('part');
                $duplicates = app(FindDuplicateParts::class)->handle($this->input('name'), $this->input('part_number'), $part instanceof Part ? $part->id : null);
                foreach ($duplicates as $duplicate) {
                    foreach ($duplicate['reasons'] as $field) {
                        if (! $validator->errors()->has($field)) {
                            $validator->errors()->add($field, __("inventory.parts.duplicate_{$field}", ['code' => $duplicate['code'], 'name' => $duplicate['name']]));
                        }
                    }
                }
            },
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
        $data = collect($this->validated())->except(['initial_qty', 'initial_serials'])->all();
        $data['unit_cost'] = Money::toSatang($data['unit_cost'] ?? null);
        if ($this->route('part') instanceof Part || ! $this->user()->can('parts.serials')) {
            unset($data['track_serial']);
        }

        return $data;
    }
}
