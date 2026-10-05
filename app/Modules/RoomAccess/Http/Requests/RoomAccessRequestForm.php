<?php

namespace App\Modules\RoomAccess\Http\Requests;

use App\Modules\RoomAccess\Models\RoomAccessItem;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\ServerRoom;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A draft request: an open room of the company, when (the end after the start), why, who goes in
 * (at least one; ID numbers checked when sent, as the room asks), the equipment, and optionally
 * the ticket / MA contract it is for (checked against what the user may see by the controller).
 */
class RoomAccessRequestForm extends FormRequest
{
    public const MAX_PEOPLE = 30;

    public const MAX_ITEMS = 50;

    public function authorize(): bool
    {
        $request = $this->route('roomRequest');

        return $request ? $this->user()->can('update', $request) : $this->user()->can('create', RoomAccessRequest::class);
    }

    public function rules(): array
    {
        return [
            // Rooms of this company only (tenant scope), and open ones.
            'server_room_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                if (! ServerRoom::query()->where('is_active', true)->whereKey((int) $value)->exists()) {
                    $fail(__('room_access.requests.room_closed'));
                }
            }],
            'planned_start' => ['required', 'date'],
            'planned_end' => ['required', 'date', 'after:planned_start'],
            'purpose' => ['required', 'string', 'max:2000'],
            'ticket_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'people' => ['required', 'array', 'min:1', 'max:'.self::MAX_PEOPLE],
            'people.*.id' => ['nullable', 'integer'],
            'people.*.name' => ['required', 'string', 'max:255'],
            'people.*.company' => ['nullable', 'string', 'max:255'],
            'people.*.phone' => ['nullable', 'string', 'max:50'],
            'people.*.id_number' => ['nullable', 'string', 'max:30'],
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.serial_number' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'items.*.direction' => ['nullable', Rule::in(RoomAccessItem::DIRECTIONS)],
            // Send it for approval right after saving: the accept popup's answer.
            'submit' => ['nullable', 'boolean'],
            'accept' => ['nullable', 'boolean'],
            'version_id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return __('room_access.fields');
    }
}
