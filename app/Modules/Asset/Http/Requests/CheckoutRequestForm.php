<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Inventory\Actions\PurchaseRequestLabels;
use App\Modules\Platform\Support\ItemPurpose;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketsForCheckout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An issue/loan request with its lines, saved as a draft or sent (submit). Whoever may only ask
 * for themselves (asset-checkouts.request without .create) is always the borrower. Each line says
 * what it is for (ItemPurpose), for which customer and ticket (one the user may see); replacing a
 * broken unit needs its serial number or a photo (a new one, or one the draft already has).
 * The lines themselves are checked by SaveCheckoutRequest.
 */
class CheckoutRequestForm extends FormRequest
{
    public const MAX_ITEMS = 30;

    public function authorize(): bool
    {
        $request = $this->route('checkout');

        return $request instanceof CheckoutRequest ? $this->user()->can('update', $request) : $this->user()->can('create', CheckoutRequest::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->user() && ! $this->user()->can('createForOthers', CheckoutRequest::class)) {
            $this->merge(['borrower_user_id' => $this->user()->id, 'borrower_name' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'borrower_user_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                // Staff of this company only (the list the form offers), or the user themself.
                if ((int) $value !== (int) $this->user()->id && ! app(UsersWithPermission::class)->handle('assets.view')->contains('id', (int) $value)) {
                    $fail(__('validation.exists', ['attribute' => __('asset.requests.fields.borrower_user_id')]));
                }
            }],
            'borrower_name' => ['required_without:borrower_user_id', 'nullable', 'string', 'max:255'],
            'borrower_department' => ['nullable', 'string', 'max:255'],
            'borrower_phone' => ['nullable', 'string', 'max:50'],
            'ticket_id' => ['nullable', 'integer', $this->visibleTicket()],
            'contract_id' => $this->contractRules(),
            'purpose' => ['nullable', 'string', 'max:2000'],
            'needed_by' => ['nullable', 'date'],
            'submit' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.item_type' => ['required', Rule::in([CheckoutItem::TYPE_ASSET, CheckoutItem::TYPE_PART])],
            'items.*.asset_id' => ['nullable', 'required_if:items.*.item_type,asset', 'integer'],
            'items.*.part_id' => ['nullable', 'required_if:items.*.item_type,part', 'integer'],
            'items.*.checkout_type' => ['nullable', Rule::in([CheckoutItem::ISSUE, CheckoutItem::LOAN])],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.due_return_date' => ['nullable', 'date', 'after_or_equal:today'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            ...ItemPurpose::rules('items.*.'),
            'items.*.ticket_id' => ['nullable', 'integer', $this->visibleTicket()],
            'items.*.contract_id' => $this->contractRules(),
            // The photo the draft already has for this line (kept when no new one comes).
            'items.*.replaced_photo_id' => ['nullable', 'integer'],
            // Bought on this purchase request (Inventory module): handing it out counts there.
            'items.*.purchase_request_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (app(PurchaseRequestLabels::class)->handle([(int) $value]) === []) {
                    $fail(__('validation.exists', ['attribute' => __('asset.requests.fields.purchase_request_id')]));
                }
            }],
            // Save the draft, then open a purchase request for this (nothing to hand out was found).
            'then_purchase' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A replacement shows the broken unit: its serial number, a new photo, or the one it had. A
     * line for a customer with projects is for one of them.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $kept = $this->route('checkout') instanceof CheckoutRequest
                ? $this->route('checkout')->getMedia(CheckoutRequest::REPLACED_PHOTOS)->pluck('id')->all()
                : [];
            $contracts = app(Modules::class)->enabled('contract')
                ? app(ContractOptions::class)->handle($this->route('checkout')?->contract_id, $this->user(), 'asset-checkouts.request')
                : [];
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                ItemPurpose::checkEvidence($validator, "items.{$index}.", $item,
                    $this->hasFile("items.{$index}.replaced_photo") || in_array((int) ($item['replaced_photo_id'] ?? 0), $kept, true));
                ItemPurpose::checkProject($validator, "items.{$index}.", $item, $contracts);
            }
        }];
    }

    public function attributes(): array
    {
        return [
            ...__('asset.requests.fields'),
            ...ItemPurpose::attributes('items.*.'),
            'items.*.ticket_id' => __('platform.item_purpose.fields.ticket_id'),
            'items.*.contract_id' => __('platform.item_purpose.fields.contract_id'),
        ];
    }

    /** A project that is there; with scope project, one of the user's team (as the form offers), or the request's. */
    private function contractRules(): array
    {
        return ['nullable', 'integer', Rule::exists('contracts', 'id')->whereNull('deleted_at'),
            function (string $attribute, mixed $value, \Closure $fail) {
                if (! ContractScope::allowsProject($this->user(), 'asset-checkouts.request', (int) $value, $this->route('checkout')?->contract_id)) {
                    $fail(__('contract.members.not_in_team'));
                }
            }];
    }

    /** A ticket the user may see (the form offers only those). */
    private function visibleTicket(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (app(TicketsForCheckout::class)->handle($this->user(), [(int) $value]) === []) {
                $fail(__('validation.exists', ['attribute' => __('asset.requests.fields.ticket_id')]));
            }
        };
    }

    /** @return array<string, mixed> */
    public function requestData(): array
    {
        return $this->safe()->except(['submit', 'then_purchase']);
    }
}
