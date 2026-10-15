<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Actions\PmCalendar;
use App\Modules\Maintenance\Actions\PmProjectedRounds;
use App\Modules\Maintenance\Http\Requests\PmPlanRequest;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PmCalendarController extends Controller
{
    public function index(
        Request $request,
        PmCalendar $calendar,
        ListCustomers $listCustomers,
        UserNames $userNames,
        UsersWithPermission $usersWithPermission,
    ): Response {
        Gate::authorize('viewAny', PmVisit::class);

        $user = $request->user();
        $filters = PmCalendar::filtersFrom($request);
        $visits = $calendar->handle($user, $filters);
        $customers = collect($listCustomers->handle(withTrashed: true))->pluck('name', 'id');
        $staff = $user->customer_id === null;
        // Rounds of the plans whose work orders the daily run has not made yet (staff only).
        $projected = $staff ? app(PmProjectedRounds::class)->handle($user, $filters) : [];
        $names = $userNames->handle([...$visits->pluck('assignee_id')->all(), ...array_column($projected, 'assignee_id')]);

        return Inertia::render('Maintenance/Visits/Calendar', [
            'visits' => $visits->map(fn (PmVisit $visit) => [
                ...$visit->only(['ulid', 'visit_no', 'round', 'status', 'calendar_date']),
                'plan' => $visit->plan?->title,
                'customer' => $customers[$visit->customer_id] ?? null,
                'assignee' => $names[$visit->assignee_id] ?? null,
                'scheduled' => $visit->scheduled_on !== null,
                'overdue' => $visit->isOverdue(),
            ])->values(),
            'projected' => array_map(fn (array $round) => [
                ...collect($round)->only(['plan_id', 'plan', 'round', 'calendar_date', 'creates_on'])->all(),
                'customer' => $customers[$round['customer_id']] ?? null,
                'assignee' => $names[$round['assignee_id']] ?? null,
            ], $projected),
            'filters' => $filters,
            // A customer account only filters by month.
            'customers' => $staff ? $listCustomers->handle() : [],
            'assignees' => $staff
                ? $usersWithPermission->handle(PmPlanRequest::ASSIGNABLE_PERMISSION)->map(fn (User $u) => $u->only(['id', 'name']))->values()
                : [],
        ]);
    }
}
