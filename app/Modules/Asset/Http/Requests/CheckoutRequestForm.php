<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Inventory\Actions\PurchaseRequestLabels;
use App\Modules\Service\Actions\TicketsForCheckout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An issue/loan request with its lines, saved as a draft or sent (submit). Whoever may only ask
 * for themselves (asset-checkouts.request without .create) is always the borrower. Parts need a
 * ticket the user may see. The lines themselves are checked by SaveCheckoutRequest.
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
            'ticket_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (app(TicketsForCheckout::class)->handle($this->user(), [(int) $value]) === []) {
                    $fail(__('validation.exists', ['attribute' => __('asset.requests.fields.ticket_id')]));
                }
            }],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->whereNull('deleted_at'),
                // With scope project: one of the user's team (as the form offers), or the one already chosen.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! ContractScope::allowsProject($this->user(), 'asset-checkouts.request', (int) $value, $this->route('checkout')?->contract_id)) {
                        $fail(__('contract.members.not_in_team'));
                    }
                }],
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

    public function attributes(): array
    {
        return __('asset.requests.fields');
    }

    /** @return array<string, mixed> */
    public function requestData(): array
    {
        return $this->safe()->except(['submit', 'then_purchase']);
    }
}
