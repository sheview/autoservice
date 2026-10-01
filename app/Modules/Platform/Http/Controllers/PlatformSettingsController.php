<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\UpdateSessionTimeout;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Platform\Support\SessionTimeout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings of the whole platform (for now: the idle time before sign-out). Needs platform.tenants,
 * from the platform tenant itself.
 */
class PlatformSettingsController extends Controller
{
    public function edit(Request $request, Impersonation $impersonation): Response
    {
        $this->authorizePlatform($request, $impersonation);

        return Inertia::render('Platform/Settings', [
            'sessionTimeout' => SessionTimeout::minutes(),
            'min' => SessionTimeout::MIN,
            'max' => SessionTimeout::MAX,
        ]);
    }

    public function update(Request $request, Impersonation $impersonation, UpdateSessionTimeout $updateSessionTimeout): RedirectResponse
    {
        $this->authorizePlatform($request, $impersonation);

        $validated = $request->validate(
            ['session_timeout_minutes' => ['required', 'integer', 'min:'.SessionTimeout::MIN, 'max:'.SessionTimeout::MAX]],
            attributes: ['session_timeout_minutes' => __('platform.fields.session_timeout_minutes')],
        );

        $updateSessionTimeout->handle((int) $validated['session_timeout_minutes'], $request->user());

        return redirect()->route('platform.settings.edit')->with('success', __('platform.settings.updated'));
    }

    private function authorizePlatform(Request $request, Impersonation $impersonation): void
    {
        abort_if($impersonation->active() || ! $request->user()->checkPermissionTo(TenantModuleController::PERMISSION), 403);
    }
}
