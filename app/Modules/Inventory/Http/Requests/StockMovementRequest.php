<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A stock change entered on the part page: receive, issue (not for a ticket) or adjust.
 * Each type needs its own permission: stock.receive, stock.issue, stock.adjust.
 */
class StockMovementRequest extends FormRequest
{
    public const MAX_QUANTITY = 1000000;

    public function authorize(): bool
    {
        $type = $this->input('type');

        return in_array($type, StockMovement::MANUAL_TYPES, true)
            && $this->user()->can('view', $this->route('part'))
            && $this->user()->can("stock.{$type}");
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(StockMovement::MANUAL_TYPES)],
            // For an adjustment this is the counted stock, which may be zero.
            'quantity' => ['required', 'integer', $this->input('type') === StockMovement::TYPE_ADJUST ? 'min:0' : 'min:1', 'max:'.self::MAX_QUANTITY],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'], // baht
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => [$this->input('type') === StockMovement::TYPE_ADJUST ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return __('inventory.fields');
    }

    /**
     * @return array{unit_cost: int|null, reference: string|null, note: string|null}
     */
    public function details(): array
    {
        $data = $this->validated();

        return [
            'unit_cost' => Money::toSatang($data['unit_cost'] ?? null),
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
        ];
    }
}
