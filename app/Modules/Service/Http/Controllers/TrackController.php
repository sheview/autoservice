<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TrackTickets;
use App\Modules\Service\Support\TicketNumber;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Track my repair" by ticket number or serial, for anyone, without signing in. Which company is
 * decided by PublicTenant (subdomain / signed-in user / code in the number / code typed in).
 * An unknown company and an unknown ticket get the very same answer, after the same search.
 */
class TrackController extends Controller
{
    public function __invoke(Request $request, PublicTenant $publicTenant, TenantContext $context, TrackTickets $track, Modules $modules): Response
    {
        $query = $request->string('q')->trim()->limit(100, '')->value();
        $company = $request->string('company')->trim()->limit(30, '')->value();
        $known = $publicTenant->known();

        $codeInNumber = TicketNumber::parse($query)['company_code'] ?? null;
        $tenant = $publicTenant->resolve($company, $codeInNumber);
        // A number of another company (TK002-... asked of company 001) is not found, even if 001 has the same number inside.
        $usable = $tenant !== null && $modules->enabled('service', $tenant)
            && ($codeInNumber === null || (int) $codeInNumber === (int) $tenant->company_code);

        // The same search either way (none of a company not found), so the answer takes as long.
        $results = $query === '' ? [] : $context->run($usable ? $tenant : null, fn () => $track->handle($query));

        return Inertia::render('Service/Track', [
            'filters' => ['company' => $known ? null : $company, 'q' => $query],
            'askCompany' => ! $known,
            'searched' => $query !== '',
            'results' => $results,
        ]);
    }
}
