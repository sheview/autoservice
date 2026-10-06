<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomAccessPerson;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\IdNumber;
use App\Modules\RoomAccess\Support\RequestHistory;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft request, or saves changes to one: the room, when, why, the ticket / contract,
 * who goes in and what equipment. ID card numbers are kept only when the room asks for them; on
 * an edit, an entrant left with a blank number keeps the one saved before (the form never shows
 * it in full). Sending it for approval is SubmitRoomAccessRequest.
 */
class SaveRoomAccessRequest
{
    public function __construct(private GenerateRoomRequestNumber $generateNumber) {}

    /**
     * @param  array{server_room_id: int, planned_start: string, planned_end: string, purpose: string,
     *     recurrence?: array{weekdays: list<int>, start_time: string, end_time: string}|null, ticket_id?: int|null,
     *     contract_id?: int|null, people: list<array{id?: int|null, name: string, company?: string|null, phone?: string|null, id_number?: string|null}>,
     *     items?: list<array{name: string, serial_number?: string|null, quantity?: int|null, direction?: string|null}>}  $data  validated
     */
    public function handle(?RoomAccessRequest $request, array $data, User $actor): RoomAccessRequest
    {
        return DB::transaction(function () use ($request, $data, $actor) {
            $room = ServerRoom::query()->findOrFail($data['server_room_id']);
            $new = $request === null;
            $request ??= new RoomAccessRequest([
                'request_no' => $this->generateNumber->handle(),
                'requester_id' => $actor->id,
                'requester_name' => $actor->name,
            ]);
            // A standing request runs from its first day's hours to its last day's.
            $recurrence = $data['recurrence'] ?? null;
            $request->fill([
                'server_room_id' => $room->id,
                'customer_id' => $room->customer_id,
                'planned_start' => $recurrence ? substr($data['planned_start'], 0, 10).' '.$recurrence['start_time'] : $data['planned_start'],
                'planned_end' => $recurrence ? substr($data['planned_end'], 0, 10).' '.$recurrence['end_time'] : $data['planned_end'],
                'recurrence' => $recurrence ? [
                    'weekdays' => collect($recurrence['weekdays'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all(),
                    'start_time' => $recurrence['start_time'],
                    'end_time' => $recurrence['end_time'],
                ] : null,
                'purpose' => $data['purpose'],
                'ticket_id' => $data['ticket_id'] ?? null,
                'contract_id' => $data['contract_id'] ?? null,
            ])->save();

            // Numbers saved before, by entrant, for those whose number is left blank on an edit.
            $kept = $new ? collect() : $request->people()->get()->keyBy('id')->map(fn (RoomAccessPerson $p) => $p->id_number);
            $request->people()->delete();
            foreach (array_values($data['people']) as $i => $person) {
                $number = $room->requires_id_number
                    ? (IdNumber::clean($person['id_number'] ?? null) ?? (isset($person['id']) ? $kept[(int) $person['id']] ?? null : null))
                    : null;
                $request->people()->create([
                    'position' => $i + 1,
                    'name' => trim($person['name']),
                    'company' => $person['company'] ?? null,
                    'phone' => $person['phone'] ?? null,
                    'id_number' => $number,
                ]);
            }

            $request->items()->delete();
            foreach (array_values($data['items'] ?? []) as $i => $item) {
                $request->items()->create([
                    'position' => $i + 1,
                    'name' => trim($item['name']),
                    'serial_number' => $item['serial_number'] ?? null,
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'direction' => $item['direction'] ?? 'in',
                ]);
            }

            if ($new) {
                RequestHistory::record($request, 'created', null, $actor);
            }

            return $request;
        });
    }
}
