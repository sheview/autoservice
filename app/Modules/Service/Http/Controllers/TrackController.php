<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TrackTickets;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Track my repair" for anyone, without signing in. The company is the one of the subdomain (or of the
 * signed-in user), or else the company code typed in (?company=, its subdomain name), so one company's
 * search never reaches another's tickets.
 */
class TrackController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, TrackTickets $track, Modules $modules): Response
    {
        $code = $request->string('company')->trim()->lower()->value();
        $query = $request->string('q')->trim()->limit(100, '')->value();

        $tenant = $context->tenant();
        $fromSubdomain = $tenant !== null && ! $tenant->is_platform;
        if (! $fromSubdomain) {
            $tenant = $code === '' ? null : Tenant::query()->where('subdomain', $code)->where('is_platform', false)->first();
        }
        $usable = $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $modules->enabled('service', $tenant);

        return Inertia::render('Service/Track', [
            'filters' => ['company' => $fromSubdomain ? null : $code, 'q' => $query],
            'askCompany' => ! $fromSubdomain,
            'company' => $usable ? $tenant->name : null,
            'companyUnknown' => $code !== '' && ! $usable && ! $fromSubdomain,
            'searched' => $usable && $query !== '',
            'results' => $usable && $query !== '' ? $context->run($tenant, fn () => $track->handle($query)) : [],
        ]);
    }
}
