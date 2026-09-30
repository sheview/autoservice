<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\Asset;
use App\Modules\Document\Http\Concerns\ServesPhotoSlots;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photos of an asset (a main one and three extras). Whoever may see the asset sees them;
 * whoever may edit it changes them.
 */
class AssetPhotoController extends Controller
{
    use ServesPhotoSlots;

    public function store(Request $request, Asset $asset, int $slot): RedirectResponse
    {
        Gate::authorize('update', $asset);

        return $this->storePhoto($request, $asset, $slot);
    }

    public function show(Asset $asset, int $slot): StreamedResponse
    {
        Gate::authorize('view', $asset);

        return $this->showPhoto($asset, $slot);
    }

    public function destroy(Asset $asset, int $slot): RedirectResponse
    {
        Gate::authorize('update', $asset);

        return $this->destroyPhoto($asset, $slot);
    }
}
