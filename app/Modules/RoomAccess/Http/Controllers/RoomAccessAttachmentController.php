<?php

namespace App\Modules\RoomAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Http\Concerns\ServesAttachments;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents attached to a request (work orders, letters, ID scans are not taken): whoever may see
 * the request reads them, the requester adds and removes them while it is a draft.
 */
class RoomAccessAttachmentController extends Controller
{
    use ServesAttachments;

    public function store(Request $request, RoomAccessRequest $roomRequest): RedirectResponse
    {
        Gate::authorize('update', $roomRequest);

        return $this->storeAttachments($request, $roomRequest);
    }

    public function show(RoomAccessRequest $roomRequest, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $roomRequest);

        return $this->showAttachment($roomRequest, $attachment);
    }

    public function destroy(RoomAccessRequest $roomRequest, int $attachment): RedirectResponse
    {
        Gate::authorize('update', $roomRequest);

        return $this->destroyAttachment($roomRequest, $attachment);
    }
}
