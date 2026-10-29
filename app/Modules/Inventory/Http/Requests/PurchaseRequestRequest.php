<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Document\Support\Attachments;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\ItemPurpose;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use App\Modules\Service\Actions\TicketsForCheckout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What to buy: the item, how many, an estimated price (baht), the product pages (web links only)
 * and what it is for (ItemPurpose, with its customer and ticket; a replacement shows the broken
 * unit's serial or a photo). Quotations may be attached. A new request may ask for more items on
 * the same form (extra_items, each with its own item fields; the reason, date, project and files
 * are shared).
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

    /** The fields each item has of its own; the others are shared by all items asked for together. */
    public const ITEM_FIELDS = [
        'item_name', 'description', 'quantity', 'unit', 'unit_price', 'links', 'item_kind', 'asset_category_id',
        'purpose', 'purpose_other', 'for_internal', 'customer_name', 'contract_id', 'ticket_id', 'replaced_serial', 'replaced_photo',
    ];

    public const MAX_EXTRA_ITEMS = 19;

    /** Empty link boxes are not links. */
    protected function prepareForValidation(): void
    {
        $clean = fn ($links) => is_array($links) ? array_values(array_filter(array_map(
            fn ($link) => is_string($link) ? trim($link) : $link,
            $links,
        ), fn ($link) => $link !== null && $link !== '')) : $links;

        if (is_array($this->input('links'))) {
            $this->merge(['links' => $clean($this->input('links'))]);
        }
        if (is_array($this->input('extra_items'))) {
            $this->merge(['extra_items' => array_map(
                fn ($item) => is_array($item) ? ['links' => $clean($item['links'] ?? null)] + $item : $item,
                $this->input('extra_items'),
            )]);
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
            'contract_id' => $this->contractRules(),
            'description' => ['nullable', 'string', 'max:5000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit' => ['required', 'string', 'max:30'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], // baht
            'links' => ['required', 'array', 'min:1', 'max:'.PurchaseRequest::MAX_LINKS],
            // Web pages only: a "javascript:" or "file:" link must never be clickable.
            'links.*' => ['required', 'string', 'max:2000', 'url:http,https'],
            // Each item says what it is for (ItemPurpose): the reason is only more detail now.
            'reason' => ['nullable', 'string', 'max:5000'],
            ...ItemPurpose::rules(),
            'ticket_id' => ['nullable', 'integer', $this->visibleTicket()],
            ...ItemPurpose::rules('extra_items.*.'),
            'extra_items.*.ticket_id' => ['nullable', 'integer', $this->visibleTicket()],
            'extra_items.*.contract_id' => $this->contractRules(),
            // Not in the past when asked; an existing request keeps the date it had.
            'needed_by' => ['required', 'date', $request instanceof PurchaseRequest ? 'nullable' : 'after_or_equal:today'],
            // What it becomes once it arrives, when the requester knows (else the buyer says).
            'item_kind' => ['nullable', Rule::in([PurchaseRequest::KIND_ASSET, PurchaseRequest::KIND_PART])],
            'asset_category_id' => ['nullable', 'required_if:item_kind,asset', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            // The issue/loan request it was asked from (only when opened).
            'checkout_request_id' => [$request instanceof PurchaseRequest ? 'prohibited' : 'nullable', 'integer',
                Rule::exists('checkout_requests', 'id')->whereNull('deleted_at')],
            // More items asked for on the same form (a new request only).
            'extra_items' => [$request instanceof PurchaseRequest ? 'prohibited' : 'nullable', 'array', 'max:'.self::MAX_EXTRA_ITEMS],
            'extra_items.*.item_name' => ['required', 'string', 'max:255'],
            'extra_items.*.description' => ['nullable', 'string', 'max:5000'],
            'extra_items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'extra_items.*.unit' => ['required', 'string', 'max:30'],
            'extra_items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'extra_items.*.links' => ['required', 'array', 'min:1', 'max:'.PurchaseRequest::MAX_LINKS],
            'extra_items.*.links.*' => ['required', 'string', 'max:2000', 'url:http,https'],
            'extra_items.*.item_kind' => ['nullable', Rule::in([PurchaseRequest::KIND_ASSET, PurchaseRequest::KIND_PART])],
            'extra_items.*.asset_category_id' => ['nullable', 'required_if:extra_items.*.item_kind,asset', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            ...Attachments::rules(),
        ];
    }

    /**
     * A replacement shows the broken unit: its serial number, a photo, or (an edit) the photo it
     * has. An item for a customer with projects is for one of them.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $request = $this->route('purchase_request');
            $hasPhoto = $request instanceof PurchaseRequest && $request->getFirstMedia(PurchaseRequest::REPLACED_PHOTO) !== null;
            $contracts = app(Modules::class)->enabled('contract')
                ? app(ContractOptions::class)->handle($request?->contract_id, $this->user(), 'purchase-requests.create')
                : [];
            $items = ['' => $this->all(), ...collect((array) $this->input('extra_items', []))
                ->mapWithKeys(fn ($item, $index) => ["extra_items.{$index}." => is_array($item) ? $item : []])->all()];

            foreach ($items as $prefix => $item) {
                ItemPurpose::checkEvidence($validator, $prefix, $item, $this->hasFile("{$prefix}replaced_photo") || ($prefix === '' && $hasPhoto));
                ItemPurpose::checkProject($validator, $prefix, $item, $contracts);
            }
        }];
    }

    public function attributes(): array
    {
        $fields = __('inventory.purchase_requests.fields');

        return [
            ...$fields,
            ...ItemPurpose::attributes(),
            ...ItemPurpose::attributes('extra_items.*.'),
            'ticket_id' => __('platform.item_purpose.fields.ticket_id'),
            'extra_items.*.ticket_id' => __('platform.item_purpose.fields.ticket_id'),
            'extra_items.*.contract_id' => __('platform.item_purpose.fields.contract_id'),
            'links.*' => $fields['links'],
            ...collect(self::ITEM_FIELDS)->mapWithKeys(fn (string $field) => ["extra_items.*.{$field}" => $fields[$field] ?? $field])->all(),
            'extra_items.*.links.*' => $fields['links'],
            ...Attachments::attributes(),
        ];
    }

    /**
     * Validated fields for SavePurchaseRequest: price in satang, no files.
     *
     * @return array<string, mixed>
     */
    public function requestData(): array
    {
        $data = $this->safe()->except(['attachments', 'extra_items']);
        $data['unit_price'] = Money::toSatang($data['unit_price'] ?? null);

        return $data;
    }

    /**
     * Every item asked for on a new request's form, the first one first: each item's own fields
     * (price in satang). See shared() for what they have in common.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        $data = $this->requestData();
        $extra = array_map(
            fn (array $item) => ['unit_price' => Money::toSatang($item['unit_price'] ?? null)] + $item,
            $this->validated('extra_items') ?? [],
        );

        return [collect($data)->only(self::ITEM_FIELDS)->all(), ...$extra];
    }

    /**
     * What all items asked for together share.
     *
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        return collect($this->requestData())->except(self::ITEM_FIELDS)->all();
    }

    /** @return list<UploadedFile> */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }

    /** A project that is there; with scope project, one of the user's team (as the form offers), or the one already chosen. */
    private function contractRules(): array
    {
        return ['nullable', 'integer', Rule::exists('contracts', 'id')->whereNull('deleted_at'),
            function (string $attribute, mixed $value, \Closure $fail) {
                if (! ContractScope::allowsProject($this->user(), 'purchase-requests.create', (int) $value, $this->route('purchase_request')?->contract_id)) {
                    $fail(__('contract.members.not_in_team'));
                }
            }];
    }

    /** A ticket the user may see (Service module; the form offers only those). */
    private function visibleTicket(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (app(TicketsForCheckout::class)->handle($this->user(), [(int) $value]) === []) {
                $fail(__('validation.exists', ['attribute' => __('platform.item_purpose.fields.ticket_id')]));
            }
        };
    }
}
