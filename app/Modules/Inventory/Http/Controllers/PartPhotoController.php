<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Http\Concerns\ServesPhotoSlots;
use App\Modules\Inventory\Models\Part;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photos of a part (a main one and three extras). Whoever may see the part sees them;
 * whoever may edit it changes them.
 */
class PartPhotoController extends Controller
{
    use ServesPhotoSlots;

    public function store(Request $request, Part $part, int $slot): RedirectResponse
    {
        Gate::authorize('update', $part);

        return $this->storePhoto($request, $part, $slot);
    }

    public function show(Part $part, int $slot): StreamedResponse
    {
        Gate::authorize('view', $part);

        return $this->showPhoto($part, $slot);
    }

    public function destroy(Part $part, int $slot): RedirectResponse
    {
        Gate::authorize('update', $part);

        return $this->destroyPhoto($part, $slot);
    }
}
