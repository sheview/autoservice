<?php

namespace App\Modules\Contract\Http\Requests;

use App\Modules\Contract\Models\Customer;
use App\Modules\Document\Support\Attachments;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer
            ? $this->user()->can('update', $customer)
            : $this->user()->can('create', Customer::class);
    }

    public function rules(): array
    {
        $customer = $this->route('customer');

        return [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,30}$/', function (string $attribute, mixed $value, Closure $fail) use ($customer) {
                // Unique per tenant, ignoring case (the tenant scope limits the query to this tenant).
                $taken = Customer::query()
                    ->whereRaw('lower(code) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($customer, fn ($q) => $q->whereKeyNot($customer->id))
                    ->exists();
                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => __('contract.fields.code')]));
                }
            }],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            ...Attachments::rules(),
        ];
    }

    public function attributes(): array
    {
        return [...__('contract.fields'), ...Attachments::attributes()];
    }

    /** @return array<string, mixed> the customer fields, without the files */
    public function customerData(): array
    {
        return $this->safe()->except('attachments');
    }

    /** @return list<UploadedFile> */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }
}
