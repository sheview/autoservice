<?php

namespace App\Modules\Service\Http\Requests;

use App\Modules\Document\Support\Attachments;
use App\Modules\Service\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('ticket'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
            'source' => ['required', Rule::in(Ticket::SOURCES)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            ...Attachments::rules(),
        ];
    }

    public function attributes(): array
    {
        return [...__('service.fields'), ...Attachments::attributes()];
    }

    /** @return array<string, mixed> the ticket fields, without the files */
    public function ticketData(): array
    {
        return $this->safe()->except('attachments');
    }

    /** @return list<UploadedFile> */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if (! in_array($this->route('ticket')->status, Ticket::OPEN_STATUSES, true)) {
                    $validator->errors()->add('title', __('service.tickets.not_open'));
                }
            },
        ];
    }
}
