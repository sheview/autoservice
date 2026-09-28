<?php

namespace App\Modules\Service\Http\Requests;

use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Contract\Actions\CoveringContracts;
use App\Modules\Service\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Ticket::class);
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
        ];
    }

    public function attributes(): array
    {
        return __('service.fields');
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
