<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\RoomAccess\Models\RoomRuleVersion;
use App\Modules\RoomAccess\Models\ServerRoom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Records the next version of a room's rules (never changing an earlier one): the summary lines,
 * the full document, from when it applies, and where it came from; logged with who did it.
 */
class PublishRoomRules
{
    /**
     * @param  array{summary: list<string>, effective_on: string, received_from?: string|null, received_on?: string|null, note?: string|null}  $data  validated
     */
    public function handle(ServerRoom $room, array $data, ?UploadedFile $file, User $actor): RoomRuleVersion
    {
        // All or nothing: a document that cannot be kept leaves no version without it.
        $version = DB::transaction(function () use ($room, $data, $actor, $file) {
            // Locked so two people saving at once do not both make the same version.
            ServerRoom::query()->lockForUpdate()->findOrFail($room->id);
            $next = (int) RoomRuleVersion::query()->where('server_room_id', $room->id)->max('version') + 1;

            $version = RoomRuleVersion::create([
                'server_room_id' => $room->id,
                'version' => $next,
                'summary' => array_values(array_filter(array_map('trim', $data['summary']), fn (string $line) => $line !== '')),
                'effective_on' => $data['effective_on'],
                'received_from' => $data['received_from'] ?? null,
                'received_on' => $data['received_on'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ]);
            if ($file !== null) {
                $version->addMedia($file)->toMediaCollection(RoomRuleVersion::FILE);
            }

            return $version;
        });

        activity()->performedOn($room)->causedBy($actor)->event('room_rules_published')
            ->withProperties(['room' => $room->name, 'version' => $version->version, 'effective_on' => $data['effective_on']])
            ->log(__('room_access.log.rules_published', ['room' => $room->name, 'version' => $version->version]));

        return $version;
    }
}
