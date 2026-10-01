<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Inventory\Http\Controllers\PartCheckoutController;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCheckout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A request to issue or lend some of a part: to a user of the company (borrower_user_id) or to
 * someone from outside (borrower_name); with scope own only to oneself. A loan needs its due date.
 */
class PartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $part = $this->route('part');

        return $part instanceof Part && PartCheckoutController::abilities($this)['request'] && $this->user()->can('view', $part);
    }

    /** With parts.issue scope own a user asks only for themselves, whatever the form sent. */
    protected function prepareForValidation(): void
    {
        if ($this->user() && ! PartCheckoutController::abilities($this)['forOthers']) {
            $this->merge(['borrower_user_id' => $this->user()->id, 'borrower_name' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(PartCheckout::TYPES)],
            // Enough of it is checked by RequestPartCheckout, under a lock.
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->whereNull('deleted_at')],
            'borrower_user_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                // Staff of this company only (the list the form offers).
                if (! app(UsersWithPermission::class)->handle('parts.view')->contains('id', (int) $value)) {
                    $fail(__('validation.exists', ['attribute' => __('inventory.part_checkouts.fields.borrower_user_id')]));
                }
            }],
            'borrower_name' => ['required_without:borrower_user_id', 'nullable', 'string', 'max:255'],
            'borrower_department' => ['nullable', 'string', 'max:255'],
            'borrower_phone' => ['nullable', 'string', 'max:50'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['required_if:type,'.PartCheckout::TYPE_LOAN, 'nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function attributes(): array
    {
        return __('inventory.part_checkouts.fields');
    }
}
