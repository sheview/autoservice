<?php

namespace App\Modules\RoomAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\RoomAccess\Actions\PublishRoomRules;
use App\Modules\RoomAccess\Models\RoomRuleVersion;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\RoomAccess\Support\RoomAccessSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The versions of a room's rules: a new version (room-access.manage; earlier versions are never
 * changed), and the full document of one version.
 */
class RoomRuleController extends Controller
{
    public const MAX_LINES = 10;

    public function store(Request $request, ServerRoom $room, PublishRoomRules $publish): RedirectResponse
    {
        abort_unless($request->user()->can(RoomAccessSettings::PERMISSION) && $request->user()->customer_id === null, 403);
        $data = $request->validate([
            'summary' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'summary.*' => ['nullable', 'string', 'max:500'],
            'effective_on' => ['required', 'date'],
            'received_from' => ['nullable', 'string', 'max:255'],
            'received_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:'.RoomRuleVersion::FILE_MAX_KB],
        ], [], __('room_access.fields'));
        $data['summary'] = array_values(array_filter(array_map(fn ($line) => trim((string) $line), $data['summary']), fn (string $line) => $line !== ''));
        if ($data['summary'] === []) {
            return back()->withErrors(['summary' => __('room_access.rules.summary_required')]);
        }

        $version = $publish->handle($room, $data, $request->file('file'), $request->user());

        return back()->with('success', __('room_access.rules.published', ['version' => $version->version]));
    }

    /**
     * The full rules of a version. Settings staff see every room's; who may see a room's rules
     * when asking to enter it is decided with the requests (step 2).
     */
    public function file(Request $request, ServerRoom $room, int $version): StreamedResponse
    {
        abort_unless($request->user()->can(RoomAccessSettings::PERMISSION) && $request->user()->customer_id === null, 403);
        $media = RoomRuleVersion::query()->where('server_room_id', $room->id)->findOrFail($version)->getFirstMedia(RoomRuleVersion::FILE);
        abort_if($media === null, 404);

        return $media->toInlineResponse($request);
    }
}
