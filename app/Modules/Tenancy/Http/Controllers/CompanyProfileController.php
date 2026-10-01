<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Actions\UpdateCompanyProfile;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The company's own profile (CompanyProfile), edited by its admin (company.update). The logo is
 * served to every signed-in user of the company, as it is printed on labels.
 */
class CompanyProfileController extends Controller
{
    public function edit(TenantContext $context): Response
    {
        Gate::authorize(CompanyProfile::PERMISSION);

        return Inertia::render('Tenancy/Company', [
            'company' => CompanyProfile::of($this->company($context)),
            'logoMaxKb' => CompanyProfile::LOGO_MAX_KB,
        ]);
    }

    public function update(Request $request, TenantContext $context, UpdateCompanyProfile $updateProfile): RedirectResponse
    {
        Gate::authorize(CompanyProfile::PERMISSION);

        $data = $request->validate([
            'service_phone' => ['nullable', 'string', 'max:50'],
            'service_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.CompanyProfile::LOGO_MAX_KB],
            'remove_logo' => ['boolean'],
        ], attributes: __('tenancy.company.fields'));

        $updateProfile->handle($this->company($context), $data, $request->file('logo'), $request->boolean('remove_logo'));

        return back()->with('success', __('tenancy.company.updated'));
    }

    public function logo(TenantContext $context): StreamedResponse
    {
        $media = $this->company($context)->getFirstMedia(CompanyProfile::LOGO);
        abort_if($media === null, 404);

        return response()->streamDownload(
            fn () => fpassthru($media->stream()),
            $media->file_name,
            ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, max-age=86400'],
            'inline',
        );
    }

    /** The company being worked in; the platform has no profile of its own. */
    private function company(TenantContext $context): Tenant
    {
        $tenant = $context->tenant();
        abort_if($tenant === null || $tenant->is_platform, 404);

        return $tenant;
    }
}
