<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\Asset;
use App\Modules\Document\Http\Concerns\ServesAttachments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to an asset (Word, Excel, PDF). Whoever may see the asset opens them;
 * whoever may edit it adds and deletes them.
 */
class AssetAttachmentController extends Controller
{
    use ServesAttachments;

    public function store(Request $request, Asset $asset): RedirectResponse
    {
        Gate::authorize('update', $asset);

        return $this->storeAttachments($request, $asset);
    }

    public function show(Asset $asset, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $asset);

        return $this->showAttachment($asset, $attachment);
    }

    public function destroy(Asset $asset, int $attachment): RedirectResponse
    {
        Gate::authorize('update', $asset);

        return $this->destroyAttachment($asset, $attachment);
    }
}
