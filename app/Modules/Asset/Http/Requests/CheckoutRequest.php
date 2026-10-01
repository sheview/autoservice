<?php

namespace App\Modules\Asset\Http\Requests;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Actions\UsersWithPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to issue or lend an asset: to a user of the company (borrower_user_id) or to someone
 * from outside (borrower_name). A loan needs its due date.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset && $this->user()->can('asset.checkout') && $this->user()->can('view', $asset);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(AssetCheckout::TYPES)],
            'borrower_user_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                // Staff of this company only (the list the form offers).
                if (! app(UsersWithPermission::class)->handle('asset.view')->contains('id', (int) $value)) {
                    $fail(__('validation.exists', ['attribute' => __('asset.checkouts.fields.borrower')]));
                }
            }],
            'borrower_name' => ['required_without:borrower_user_id', 'nullable', 'string', 'max:255'],
            'borrower_department' => ['nullable', 'string', 'max:255'],
            'borrower_phone' => ['nullable', 'string', 'max:50'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['required_if:type,'.AssetCheckout::TYPE_LOAN, 'nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function attributes(): array
    {
        return __('asset.checkouts.fields');
    }
}
