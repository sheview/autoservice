<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Turnstile;
use App\Modules\Service\Actions\SaveFieldTechnician;
use App\Modules\Service\Models\FieldTechnician;
use App\Modules\Service\Support\ThaiProvinces;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page outside technicians around the country open from the link the company sends them
 * (/join-technician on the company's host, or /t/{code}/join-technician), without an account:
 * they say who they are, how to reach them, where they work and what they take on, and agree to
 * the details being kept. They wait for staff to approve them. Only a company that keeps the
 * register (module "field_technicians") has the page; for any other it reads "not found".
 */
class FieldTechnicianSignupController extends Controller
{
    public function __construct(private TenantContext $context, private Modules $modules) {}

    public function showOnHost(PublicTenant $publicTenant): Response
    {
        return $this->show($publicTenant->resolve());
    }

    public function showOnPath(string $code): Response
    {
        return $this->show(CompanyCodes::tenant($code));
    }

    public function storeOnHost(Request $request, PublicTenant $publicTenant, SaveFieldTechnician $save): RedirectResponse
    {
        return $this->store($request, $publicTenant->resolve(), $save);
    }

    public function storeOnPath(Request $request, string $code, SaveFieldTechnician $save): RedirectResponse
    {
        return $this->store($request, CompanyCodes::tenant($code), $save);
    }

    private function usable(?Tenant $tenant): bool
    {
        return $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('field_technicians', $tenant);
    }

    private function show(?Tenant $tenant): Response
    {
        $usable = $this->usable($tenant);

        return Inertia::render('Service/FieldTechnicianSignup', [
            'company' => $usable ? $tenant->name : null,
            'provinces' => ThaiProvinces::ALL,
            'skills' => FieldTechnician::SKILLS,
            'maxProvinces' => FieldTechnician::MAX_PROVINCES,
            'captcha' => $usable ? Turnstile::siteKey() : null,
        ]);
    }

    private function store(Request $request, ?Tenant $tenant, SaveFieldTechnician $save): RedirectResponse
    {
        abort_unless($this->usable($tenant), 404);
        $data = $request->validate([
            ...FieldTechnicianController::fieldRules(),
            'consent' => ['accepted'],
        ], [], __('service.field_technicians.fields'));

        if (! Turnstile::passes($request->string('cf-turnstile-response')->value(), $request->ip())) {
            throw ValidationException::withMessages(['captcha' => __('service.field_technicians.captcha_failed')]);
        }

        $this->context->run($tenant, fn () => $save->handle(null, $data));

        return back()->with('success', __('service.field_technicians.signed_up'));
    }
}
