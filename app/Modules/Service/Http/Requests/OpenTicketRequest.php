<?php

namespace App\Modules\Service\Http\Requests;

use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Document\Support\Attachments;
use App\Modules\Service\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class OpenTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Ticket::class);
    }

    /**
     * A customer account opens tickets for its own customer, through the portal, without choosing
     * the contract (OpenTicket picks the covering one) or an assignee.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if ($user->customer_id !== null) {
            $this->merge([
                'customer_id' => $user->customer_id,
                'source' => 'portal',
                'contract_id' => null,
                'assignee_id' => null,
                // The person reporting is the account holder unless they name someone else.
                'contact_name' => $this->filled('contact_name') ? $this->input('contact_name') : $user->name,
                'contact_phone' => $this->filled('contact_phone') ? $this->input('contact_phone') : $user->phone,
            ]);
        }

        $this->merge(['device_serial_unknown' => $this->boolean('device_serial_unknown')]);
    }

    /** No asset picked: the device is not in the system and must be described by hand. */
    private function unregistered(): bool
    {
        return ! $this->filled('asset_id');
    }

    /**
     * Customer, asset, contract and assignee must be rows of the current tenant (RLS + tenant scope).
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'asset_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'source' => ['required', Rule::in(Ticket::SOURCES)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            // A device not in the system: what it is, and its serial unless that is knowingly unknown.
            'device_name' => [Rule::requiredIf($this->unregistered()), 'nullable', 'string', 'max:255'],
            'device_brand' => [Rule::requiredIf($this->unregistered()), 'nullable', 'string', 'max:255'],
            'device_model' => [Rule::requiredIf($this->unregistered()), 'nullable', 'string', 'max:255'],
            'device_serial' => [Rule::requiredIf($this->unregistered() && ! $this->boolean('device_serial_unknown')), 'nullable', 'string', 'max:255'],
            'device_serial_unknown' => ['boolean'],
            'device_location' => ['nullable', 'string', 'max:255'],
            'device_ip' => ['nullable', 'ip'],
            ...Attachments::rules(),
        ];
    }

    public function attributes(): array
    {
        return [...__('service.fields'), ...Attachments::attributes()];
    }

    /**
     * @return array<string, mixed> the ticket fields, without the files. A registered asset brings its
     *                              own device facts (OpenTicket), so what was typed for it is dropped.
     */
    public function ticketData(): array
    {
        $data = $this->safe()->except('attachments');
        if (! $this->unregistered()) {
            return collect($data)->except(self::DEVICE_FIELDS)->all();
        }
        if ($data['device_serial_unknown']) {
            $data['device_serial'] = null;
        }

        return $data;
    }

    private const DEVICE_FIELDS = ['device_name', 'device_brand', 'device_model', 'device_serial', 'device_serial_unknown', 'device_location', 'device_ip'];

    /** @return list<UploadedFile> */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }

    /**
     * The asset must be one the user can see and belong to the chosen customer; the contract must
     * cover that customer/asset today; only users with ticket.assign pick an assignee.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $assetId = $this->integer('asset_id') ?: null;
                $customerId = $this->integer('customer_id') ?: null;

                if ($assetId !== null) {
                    $asset = app(AssetSummaries::class)->handle($this->user(), ['ids' => [$assetId]])[0] ?? null;
                    if ($asset === null) {
                        $validator->errors()->add('asset_id', __('validation.exists', ['attribute' => __('service.fields.asset_id')]));

                        return;
                    }
                    if ($customerId !== null && $asset['customer_id'] !== $customerId) {
                        $validator->errors()->add('asset_id', __('service.tickets.asset_other_customer'));
                    }
                    $customerId ??= $asset['customer_id'];
                }

                if ($this->filled('contract_id')) {
                    $covering = collect(app(CoveringContracts::class)->handle($customerId, $assetId))->pluck('id');
                    if (! $covering->contains($this->integer('contract_id'))) {
                        $validator->errors()->add('contract_id', __('service.tickets.contract_not_covering'));
                    }
                }

                if ($this->filled('assignee_id') && ! $this->user()->can('ticket.assign')) {
                    $validator->errors()->add('assignee_id', __('service.tickets.cannot_assign'));
                }
            },
        ];
    }
}
