<?php

namespace App\Modules\RoomAccess\Http\Requests;

use App\Modules\RoomAccess\Models\RoomAccessItem;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\RoomSchedule;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // A standing request: the weekdays and the hours of each day, within the period.
            'recurrence' => ['nullable', 'array'],
            'recurrence.weekdays' => ['required_with:recurrence', 'array', 'min:1'],
            'recurrence.weekdays.*' => ['integer', 'between:1,7'],
            'recurrence.start_time' => ['required_with:recurrence', 'date_format:H:i'],
            'recurrence.end_time' => ['required_with:recurrence', 'date_format:H:i', 'after:recurrence.start_time'],
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

    /** A standing request covers at most RoomSchedule::MAX_DAYS and at least one of its weekdays. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $rule = $this->input('recurrence');
            if (! is_array($rule) || $validator->errors()->hasAny(['planned_start', 'planned_end', 'recurrence.weekdays', 'recurrence.weekdays.*'])) {
                return;
            }
            $from = CarbonImmutable::parse(substr((string) $this->input('planned_start'), 0, 10));
            $to = CarbonImmutable::parse(substr((string) $this->input('planned_end'), 0, 10));
            if ($from->diffInDays($to) > RoomSchedule::MAX_DAYS) {
                $validator->errors()->add('planned_end', __('room_access.schedule.too_long', ['days' => RoomSchedule::MAX_DAYS]));

                return;
            }
            $days = array_map('intval', (array) ($rule['weekdays'] ?? []));
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                if (in_array($day->isoWeekday(), $days, true)) {
                    return;
                }
            }
            $validator->errors()->add('recurrence.weekdays', __('room_access.schedule.no_days'));
        }];
    }

    public function attributes(): array
    {
        return __('room_access.fields');
    }
}
