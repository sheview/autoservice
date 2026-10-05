<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicLookupGuard;
use App\Modules\Service\Actions\TicketByToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A ticket's tracking link, without signing in: /track/{token} on the company's own host (or of the
 * signed-in user's company), or /t/{company code}/track/{token} on the shared host, where the code
 * in the link names the company. The token is looked for in that company only; anything wrong
 * reads the same "not found".
 */
class TrackTokenController extends Controller
{
    public function __construct(private TenantContext $context, private TicketByToken $ticketByToken, private Modules $modules) {}

    public function onHost(PublicTenant $publicTenant, string $token): Response
    {
        return $this->show($publicTenant->resolve(), $token);
    }

    public function onPath(string $code, string $token): Response
    {
        return $this->show(CompanyCodes::tenant($code), $token);
    }

    private function show(?Tenant $tenant, string $token): Response
    {
        $usable = $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('service', $tenant);
        $ticket = $this->context->run($usable ? $tenant : null, fn () => $this->ticketByToken->handle($token));
        if ($ticket === null) {
            PublicLookupGuard::missed(request()->ip(), $usable ? $tenant : null, 'track-link');
        }

        return Inertia::render('Service/TrackLink', [
            'company' => $ticket ? $tenant->name : null,
            'ticket' => $ticket,
        ]);
    }
}
