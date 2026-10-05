<?php

namespace App\Modules\Labeling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetCard;
use App\Modules\Asset\Models\Asset;
use App\Modules\Contract\Actions\ContractsForAsset;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Service\Actions\TicketsForAsset;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page behind an asset's QR code: /q/{asset code} on the company's host (or of the signed-in
 * user's company), /t/{company code}/q/{asset code} on the shared one. Staff of that company who are
 * signed in get the full mobile page to work on the device; anyone else gets the public page
 * (QrPublicController), whatever they are — the page never guesses who is holding the phone.
 */
class QrController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private Modules $modules,
        private QrPublicController $public,
    ) {}

    public function onHost(Request $request, PublicTenant $publicTenant, string $code): Response
    {
        return $this->show($request, $publicTenant->resolve(), $code);
    }

    public function onPath(Request $request, string $company, string $code): Response
    {
        return $this->show($request, CompanyCodes::tenant($company), $code);
    }

    /** "Staff sign in" from the public page: signing in (auth) brings them back to the same device. */
    public function signInOnHost(Request $request, string $code): RedirectResponse
    {
        return $this->back($request, $code, null);
    }

    public function signInOnPath(Request $request, string $company, string $code): RedirectResponse
    {
        return $this->back($request, $code, $company);
    }

    private function back(Request $request, string $code, ?string $company): RedirectResponse
    {
        $path = '/q/'.rawurlencode($code);

        return redirect()->to(($company !== null ? "/t/{$company}" : '').$path.($request->filled('k') ? '?k='.urlencode((string) $request->input('k')) : ''));
    }

    private function show(Request $request, ?Tenant $tenant, string $code): Response
    {
        $user = $request->user();
        $staff = $tenant !== null && $user !== null && $user->customer_id === null
            && $this->context->id() === $tenant->id && $user->can('assets.view');

        if (! $staff) {
            return $this->public->page($request, $tenant, $code);
        }

        $card = app(AssetCard::class)->handle($user, $code);
        if ($card === null) {
            return Inertia::render('Labeling/Qr', ['code' => $code, 'asset' => null, 'search' => route('asset.assets.index', ['search' => $code])]);
        }

        $serviceOn = $this->modules->enabled('service') && $user->can('tickets.view');
        $tickets = $serviceOn ? collect(app(TicketsForAsset::class)->handle($card['id'], 30)) : collect();
        $openStatuses = [Ticket::STATUS_PENDING_REVIEW, ...Ticket::OPEN_STATUSES];
        $open = $tickets->filter(fn (array $t) => in_array($t['status'], $openStatuses, true))->values();
        $asset = Asset::find($card['id']);

        return Inertia::render('Labeling/Qr', [
            'code' => $code,
            'asset' => [
                ...$card,
                'customer' => $card['customer_id'] && $this->modules->enabled('contract')
                    ? collect(app(ListCustomers::class)->handle(withTrashed: true))->firstWhere('id', $card['customer_id'])['name'] ?? null
                    : null,
                'contracts' => $this->modules->enabled('contract') && $user->can('contracts.view')
                    ? array_values(array_filter(app(ContractsForAsset::class)->handle($card['id']), fn (array $c) => $c['covering'] || $c['phase'] === 'upcoming'))
                    : [],
            ],
            'openTickets' => $open,
            'history' => $tickets->filter(fn (array $t) => in_array($t['status'], [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true))->take(5)->values(),
            'branches' => $user->can('move', $asset) ? Branch::orderBy('name')->get(['id', 'name']) : [],
            'publicUrl' => PublicUrl::forTenant($tenant, '/q/'.rawurlencode($card['asset_code'])),
            'can' => [
                'openTicket' => $serviceOn && $user->can('tickets.create'),
                'issueParts' => $this->modules->enabled('inventory') && $user->can('parts.issue'),
                'checkout' => $user->can('asset-checkouts.request') || $user->can('asset-checkouts.create'),
                'move' => $user->can('move', $asset),
            ],
        ]);
    }
}
