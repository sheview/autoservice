<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Modules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a delivery becomes: an asset (in a category, at a place) for whoever may add assets, or
 * stock of a part (an existing one, or a new one, its code typed or made up) for whoever records stock.
 */
class RegisterReceiptForm extends FormRequest
{
    public function authorize(): bool
    {
        $request = $this->route('purchase_request');
        if (! $request instanceof PurchaseRequest || ! $this->user()->can('register', $request)) {
            return false;
        }

        return match ($this->input('as')) {
            PurchaseReceipt::AS_ASSET => app(Modules::class)->enabled('asset') && $this->user()->can('assets.create'),
            PurchaseReceipt::AS_PART => $this->user()->can('stock-movements.create')
                && (filled($this->input('part_id')) || $this->user()->can('parts.create')),
            default => true, // the rules say what is wrong
        };
    }

    public function rules(): array
    {
        $asset = $this->input('as') === PurchaseReceipt::AS_ASSET;

        return [
            'as' => ['required', Rule::in([PurchaseReceipt::AS_ASSET, PurchaseReceipt::AS_PART])],
            'category_id' => [Rule::requiredIf($asset), 'nullable', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'location' => ['nullable', 'string', 'max:255'],
            'part_id' => ['nullable', 'integer', Rule::exists('parts', 'id')->whereNull('deleted_at')],
            // A new part without a code gets the next one (GeneratePartCode).
            'part_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/',
                // Unique per tenant, ignoring case, like the part form.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (Part::query()->whereRaw('lower(code) = ?', [mb_strtolower(trim((string) $value))])->exists()) {
                        $fail(__('validation.unique', ['attribute' => __('inventory.purchase_requests.fields.part_code')]));
                    }
                }],
            'track_serial' => ['nullable', 'boolean'],
        ];
    }

    /** track_serial of a new part: only whoever holds parts.serials decides it. */
    public function registerData(): array
    {
        $data = $this->validated();
        if (! $this->user()->can('parts.serials')) {
            unset($data['track_serial']);
        }

        return $data;
    }

    public function attributes(): array
    {
        return __('inventory.purchase_requests.fields');
    }
}
