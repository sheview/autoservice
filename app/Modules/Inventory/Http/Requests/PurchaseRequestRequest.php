<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Document\Support\Attachments;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * What to buy: the item, how many, an estimated price (baht), the product pages (web links only)
 * and why. Quotations may be attached.
 */
class PurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('purchase_request');

        return $request instanceof PurchaseRequest
            ? $this->user()->can('update', $request)
            : $this->user()->can('create', PurchaseRequest::class);
    }

    /** Empty link boxes are not links. */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('links'))) {
            $this->merge(['links' => array_values(array_filter(array_map(
                fn ($link) => is_string($link) ? trim($link) : $link,
                $this->input('links'),
            ), fn ($link) => $link !== null && $link !== ''))]);
        }
    }

    /**
     * Everything the approver needs to decide is required: what, how many, by when, why, and
     * at least one product page to buy from.
     */
    public function rules(): array
    {
        $request = $this->route('purchase_request');

        return [
            'item_name' => ['required', 'string', 'max:255'],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:5000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit' => ['required', 'string', 'max:30'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], // baht
            'links' => ['required', 'array', 'min:1', 'max:'.PurchaseRequest::MAX_LINKS],
            // Web pages only: a "javascript:" or "file:" link must never be clickable.
            'links.*' => ['required', 'string', 'max:2000', 'url:http,https'],
            'reason' => ['required', 'string', 'max:5000'],
            // Not in the past when asked; an existing request keeps the date it had.
            'needed_by' => ['required', 'date', $request instanceof PurchaseRequest ? 'nullable' : 'after_or_equal:today'],
            ...Attachments::rules(),
        ];
    }

    public function attributes(): array
    {
        return [...__('inventory.purchase_requests.fields'), 'links.*' => __('inventory.purchase_requests.fields.links'), ...Attachments::attributes()];
    }

    /**
     * Validated fields for SavePurchaseRequest: price in satang, no files.
     *
     * @return array<string, mixed>
     */
    public function requestData(): array
    {
        $data = $this->safe()->except('attachments');
        $data['unit_price'] = Money::toSatang($data['unit_price'] ?? null);

        return $data;
    }

    /** @return list<UploadedFile> */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }
}
