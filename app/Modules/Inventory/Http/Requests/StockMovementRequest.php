<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\PartSerials;
use App\Modules\Platform\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A stock change entered on the part page (not for a ticket): receive, issue, loan, spare,
 * return or adjust: stock-movements.create (StockMovement::permissionFor()).
 */
class StockMovementRequest extends FormRequest
{
    public const MAX_QUANTITY = 1000000;

    public function authorize(): bool
    {
        $type = $this->input('type');

        return in_array($type, StockMovement::MANUAL_TYPES, true)
            && $this->user()->can('view', $this->route('part'))
            && $this->user()->can(StockMovement::permissionFor($type));
    }

    /** The part is followed by serial number: its stock moves by pieces, not a typed quantity. */
    public function tracked(): bool
    {
        return (bool) $this->route('part')?->track_serial;
    }

    public function rules(): array
    {
        $tracked = $this->tracked();
        $receive = $this->input('type') === StockMovement::TYPE_RECEIVE;

        return [
            'type' => ['required', Rule::in(StockMovement::MANUAL_TYPES)],
            // For an adjustment this is the counted stock, which may be zero.
            'quantity' => [$tracked ? 'nullable' : 'required', 'integer', $this->input('type') === StockMovement::TYPE_ADJUST ? 'min:0' : 'min:1', 'max:'.self::MAX_QUANTITY],
            // A tracked part: the serials received (one per piece), or the pieces chosen.
            'serials' => [$tracked && $receive ? 'required' : 'prohibited', 'array', 'max:1000'],
            'serials.*' => ['nullable', 'string', 'max:'.PartSerials::MAX_LENGTH],
            'unit_ids' => [$tracked && ! $receive ? 'required' : 'prohibited', 'array', 'max:1000'],
            'unit_ids.*' => ['integer'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'warranty_until' => ['nullable', 'date'],
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
     * @return array{unit_cost: int|null, reference: string|null, note: string|null, serials: list<string>,
     *     unit_ids: list<int>, supplier: string|null, warranty_until: string|null}
     */
    public function details(): array
    {
        $data = $this->validated();

        return [
            'unit_cost' => Money::toSatang($data['unit_cost'] ?? null),
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'serials' => PartSerials::clean($data['serials'] ?? []),
            'unit_ids' => array_map('intval', $data['unit_ids'] ?? []),
            'supplier' => $data['supplier'] ?? null,
            'warranty_until' => $data['warranty_until'] ?? null,
        ];
    }
}
