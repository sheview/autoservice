<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\SearchContracts;
use App\Modules\Inventory\Actions\PartUsageReport;
use App\Modules\Maintenance\Actions\PmDashboard;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketDashboard;
use App\Modules\Survey\Actions\SurveyReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page: what needs attention now, one card per module the user may see. A card is null
 * when its module is off or the user lacks its permission. The platform tenant has no company
 * data, so its users get the shortcuts only.
 */
class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        Modules $modules,
        TicketDashboard $tickets,
        PmDashboard $pm,
        SearchContracts $contracts,
        PartUsageReport $parts,
        SurveyReport $surveys,
    ): Response {
        $user = $request->user();
        $on = fn (string $module, string $permission) => $modules->enabled($module) && $user->can($permission);
        $staff = $user->customer_id === null;

        return Inertia::render('Dashboard', [
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
