<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A delivery of a purchase request: how many came, and what the buyer read off the goods
 * (brand, model, serial numbers one per line, the price paid in baht), what it goes into the system
 * as when the request does not say, and whether to hand it over at once. Checked against what is
 * still to come in ReceivePurchase.
 */
class ReceivePurchaseForm extends FormRequest
{
    public const MAX_SERIALS = 500;

    public function authorize(): bool
    {
        $request = $this->route('purchase_request');

        if (! $request instanceof PurchaseRequest || ! $this->user()->can('receive', $request)) {
            return false;
        }

        // What it goes into the system as: adding assets, or recording stock (and making a part).
        return match ($this->input('item_kind') ?? $request->item_kind) {
            PurchaseRequest::KIND_ASSET => app(Modules::class)->enabled('asset') && $this->user()->can('assets.create'),
            PurchaseRequest::KIND_PART => $this->user()->can('stock-movements.create'),
            default => true, // the rules say what is missing
        };
    }

    /** Serial numbers come as text, one per line (or a list); blank lines are dropped. */
    protected function prepareForValidation(): void
    {
        $serials = $this->input('serials');
        if (is_string($serials)) {
            $serials = preg_split('/\r\n|\r|\n/', $serials) ?: [];
        }
        if (is_array($serials)) {
            $this->merge(['serials' => array_values(array_filter(
                array_map(fn ($serial) => is_scalar($serial) ? trim((string) $serial) : $serial, $serials),
                fn ($serial) => $serial !== '' && $serial !== null,
            ))]);
        }
    }

    public function rules(): array
    {
        $request = $this->route('purchase_request');
        $kind = $this->input('item_kind') ?? $request?->item_kind;

        return [
            // What it becomes: said here when the request does not say (then kept for it).
            'item_kind' => [Rule::requiredIf($request?->item_kind === null), 'nullable', Rule::in([PurchaseRequest::KIND_ASSET, PurchaseRequest::KIND_PART])],
            'asset_category_id' => [Rule::requiredIf($kind === PurchaseRequest::KIND_ASSET && ($request?->asset_category_id === null || $this->filled('item_kind'))),
                'nullable', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'location' => ['nullable', 'string', 'max:255'],
            'part_id' => ['nullable', 'integer', Rule::exists('parts', 'id')->whereNull('deleted_at')],
            'part_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/'],
            // Then hand everything registered over to whoever asked for it.
            'hand_out' => ['boolean'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], // baht
            'serials' => ['nullable', 'array', 'max:'.self::MAX_SERIALS],
            'serials.*' => ['string', 'max:255', 'distinct:ignore_case'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [...__('inventory.purchase_requests.fields'), 'serials.*' => __('inventory.purchase_requests.fields.serials')];
    }

    /** @return array<string, mixed> for ReceivePurchase: price in satang */
    public function receiptData(): array
    {
        $data = collect($this->validated())->except('hand_out')->all();
        $data['unit_price'] = Money::toSatang($data['unit_price'] ?? null);

        return $data;
    }
}
