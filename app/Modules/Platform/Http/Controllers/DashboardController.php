<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetTrend;
use App\Modules\Contract\Actions\SearchContracts;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Actions\PartUsageReport;
use App\Modules\Maintenance\Actions\PmDashboard;
use App\Modules\Platform\Actions\PlatformDashboard;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketDashboard;
use App\Modules\Service\Actions\TicketTrend;
use App\Modules\Survey\Actions\SurveyReport;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page (dashboard.view): what needs attention now, one card per module the user may see,
 * and charts of repairs and assets over a calendar year (?year=, this year by default). A card is
 * null when its module is off or the user lacks its permission; each card counts only what the
 * user may see (the scoped searches of its module, e.g. a technician's own work). The platform
 * tenant has no company data, so its users get the shortcuts only. Without dashboard.view the
 * user is sent to the first page of their menu.
 */
class DashboardController extends Controller
{
    /** The earliest year the charts may be asked for. */
    public const FIRST_YEAR = 2000;

    public const PERMISSION = 'dashboard.view';

    public function __invoke(
        Request $request,
        Modules $modules,
        TicketDashboard $tickets,
        PmDashboard $pm,
        SearchContracts $contracts,
        PartUsageReport $parts,
        SurveyReport $surveys,
        TicketTrend $ticketTrend,
        AssetTrend $assetTrend,
        TenantContext $context,
        PlatformDashboard $platformDashboard,
    ): Response|RedirectResponse {
        $user = $request->user();
        // The platform's own workspace: the customer companies instead of company work.
        $platformHome = ($context->tenant()?->is_platform ?? false) && $user->can('platform.impersonate');

        if (! $platformHome && ! $user->can(self::PERMISSION)) {
            $first = $modules->navigation($user->getAllPermissions()->pluck('name'), $user->customer_id !== null)[0]['href'] ?? null;
            abort_if($first === null, 403);

            return redirect($first);
        }

        $on = fn (string $module, string $permission) => $modules->enabled($module) && $user->can($permission);
        $staff = $user->customer_id === null;
        // Survey figures are for the whole company, or one customer: not for a scope "own".
        $surveyScope = DataScope::of($user, 'surveys.view');

        // The charts show one calendar year: this year unless an earlier one is picked.
        $thisYear = now()->year;
        $year = $request->integer('year', $thisYear);
        $year = $year >= self::FIRST_YEAR && $year <= $thisYear ? $year : $thisYear;

        return Inertia::render('Dashboard', [
            'platform' => $platformHome ? [
                ...$platformDashboard->handle(),
                'can' => ['manage' => $user->can('platform.tenants')],
            ] : null,
            'trends' => function () use ($on, $user, $year, $thisYear, $ticketTrend, $assetTrend) {
                $tickets = $on('service', 'tickets.view') ? $ticketTrend->handle($user, $year) : null;
                $assets = $on('asset', 'assets.view') ? $assetTrend->handle($user, $year) : null;
                if ($tickets === null && $assets === null) {
                    return null;
                }
                // Years to pick from: from the oldest data (or the last few years) up to this one.
                $first = min(array_filter([$tickets['first_year'] ?? null, $assets['first_year'] ?? null, $thisYear - TicketTrend::YEARS + 1]));

                return [
                    'year' => $year,
                    'years' => range($thisYear, max($first, self::FIRST_YEAR)),
                    'tickets' => $tickets,
                    'assets' => $assets,
                ];
            },
            'tickets' => $on('service', 'tickets.view') ? $tickets->handle($user) : null,
            'pm' => $on('maintenance', 'pm-visits.view') ? $pm->handle($user) : null,
            // Within the user's contracts.view (a customer account: its own contracts).
            'contracts' => $on('contract', 'contracts.view') ? [
                'expiring' => $contracts->handle(['phase' => 'expiring'], $user)->count(),
            ] : null,
            // Stock is internal to the company.
            'parts' => $staff && $on('inventory', 'parts.view') ? collect($parts->handle(now()->startOfMonth(), now()))->only(['low', 'out'])->all() : null,
            'surveys' => $on('survey', 'surveys.view') && $modules->enabled('service')
                && in_array($surveyScope, [PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH, PermissionCatalog::SCOPE_CUSTOMER], true)
                ? collect($surveys->handle(now()->startOfMonth(), now(),
                    $surveyScope === PermissionCatalog::SCOPE_CUSTOMER ? (int) $user->customer_id : null))->only(['average', 'answered', 'sent'])->all()
                : null,
            'can' => [
                'createTicket' => $modules->enabled('service') && $user->can('tickets.create'),
                'reports' => $on('reporting', 'reports.view'),
            ],
        ]);
    }
}
