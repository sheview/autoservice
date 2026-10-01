<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetTrend;
use App\Modules\Contract\Actions\SearchContracts;
use App\Modules\Inventory\Actions\PartUsageReport;
use App\Modules\Maintenance\Actions\PmDashboard;
use App\Modules\Platform\Actions\PlatformDashboard;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketDashboard;
use App\Modules\Service\Actions\TicketTrend;
use App\Modules\Survey\Actions\SurveyReport;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page: what needs attention now, one card per module the user may see, and charts of
 * repairs and assets over a calendar year (?year=, this year by default). A card is null when its
 * module is off or the user lacks its permission. The platform tenant has no company data, so its
 * users get the shortcuts only.
 */
class DashboardController extends Controller
{
    /** The earliest year the charts may be asked for. */
    public const FIRST_YEAR = 2000;

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
    ): Response {
        $user = $request->user();
        // The platform's own workspace: the customer companies instead of company work.
        $platformHome = ($context->tenant()?->is_platform ?? false) && $user->can('platform.impersonate');
        $on = fn (string $module, string $permission) => $modules->enabled($module) && $user->can($permission);
        $staff = $user->customer_id === null;

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
                $tickets = $on('service', 'ticket.view') ? $ticketTrend->handle($user, $year) : null;
                $assets = $on('asset', 'asset.view') ? $assetTrend->handle($user, $year) : null;
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
            'tickets' => $on('service', 'ticket.view') ? $tickets->handle($user) : null,
            'pm' => $on('maintenance', 'pm.view') ? $pm->handle($user) : null,
            'contracts' => $staff && $on('contract', 'contract.view') ? [
                'expiring' => $contracts->handle(['phase' => 'expiring'])->count(),
            ] : null,
            'parts' => $staff && $on('inventory', 'part.view') ? collect($parts->handle(now()->startOfMonth(), now()))->only(['low', 'out'])->all() : null,
            'surveys' => $staff && $on('survey', 'survey.view') && $modules->enabled('service')
                ? collect($surveys->handle(now()->startOfMonth(), now()))->only(['average', 'answered', 'sent'])->all()
                : null,
            'can' => [
                'createTicket' => $modules->enabled('service') && $user->can('ticket.create'),
                'reports' => $staff && $on('reporting', 'report.view'),
            ],
        ]);
    }
}
