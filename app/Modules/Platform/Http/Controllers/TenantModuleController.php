<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\SetTenantModules;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform switches business modules on or off for a customer tenant.
 * Only from the platform tenant itself (not while impersonating), with platform.tenants.
 */
class TenantModuleController extends Controller
{
    public const PERMISSION = 'platform.tenants';

    public function edit(Request $request, Tenant $tenant, Modules $modules, Impersonation $impersonation): Response
    {
        $this->authorizePlatform($request, $tenant, $impersonation);

        return Inertia::render('Platform/Tenants/Modules', [
            'tenant' => $tenant->only(['ulid', 'name', 'subdomain']),
            'modules' => $modules->states($tenant),
        ]);
    }

    public function update(Request $request, Tenant $tenant, Modules $modules, Impersonation $impersonation, SetTenantModules $setModules): RedirectResponse
    {
        $this->authorizePlatform($request, $tenant, $impersonation);

        $validated = $request->validate([
            'modules' => ['required', 'array:'.implode(',', $modules->toggleable())],
            'modules.*' => ['boolean'],
        ]);

        $setModules->handle($tenant, array_map('boolval', $validated['modules']));

        return redirect()->route('platform.impersonation.index')->with('success', __('platform.modules.updated', ['tenant' => $tenant->name]));
    }

    private function authorizePlatform(Request $request, Tenant $tenant, Impersonation $impersonation): void
    {
        abort_if($tenant->is_platform, 404);
        abort_if($impersonation->active() || ! $request->user()->checkPermissionTo(self::PERMISSION), 403);
    }
}
