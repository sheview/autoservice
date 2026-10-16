<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\RoomAccess\Models\ServerRoom;

/**
 * The rooms to choose from on the calendar and the report: by customer, then name.
 */
class RoomOptions
{
    /**
     * @return list<array{ulid: string, name: string, customer: string}>
     */
    public function handle(): array
    {
        $customers = app(CustomerLabelNames::class)->handle();

        return ServerRoom::query()->orderBy('name')->get()
            ->map(fn (ServerRoom $room) => ['ulid' => $room->ulid, 'name' => $room->name, 'customer' => $customers[$room->customer_id] ?? '-'])
            ->sortBy(fn (array $room) => $room['customer'].' '.$room['name'])->values()->all();
    }
}
