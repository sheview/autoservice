<?php

namespace App\Modules\Survey\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Support\Modules;
use App\Modules\Survey\Actions\SearchTicketSurveys;
use App\Modules\Survey\Actions\SummariseTicketSurveys;
use App\Modules\Survey\Models\TicketSurvey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SurveyController extends Controller
{
    public function index(
        Request $request,
        SearchTicketSurveys $search,
        SummariseTicketSurveys $summarise,
        ListCustomers $listCustomers,
        UserNames $userNames,
        Modules $modules,
    ): Response {
        Gate::authorize('viewAny', TicketSurvey::class);

        $filters = SearchTicketSurveys::filtersFrom($request);
        $query = $search->handle($request->user(), $filters);
        $surveys = (clone $query)->paginate(20)->withQueryString();

        $customers = $modules->enabled('contract') ? $listCustomers->handle(withTrashed: true) : [];
        $customerNames = collect($customers)->pluck('name', 'id');
        // Every technician who has a survey the user sees, for the filter (and the names of this page).
        $visible = fn () => SearchTicketSurveys::visibleTo(TicketSurvey::query(), $request->user());
        $technicians = $userNames->handle($visible()->whereNotNull('assignee_id')->distinct()->pluck('assignee_id')->all());
        // Customers for the filter: everyone's for whoever sees every survey, otherwise only
        // those of the surveys the user sees.
        $filterCustomers = $modules->enabled('contract') ? $listCustomers->handle() : [];
        if (! in_array(DataScope::of($request->user(), 'surveys.view'), [PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH], true)) {
            $reachable = $visible()->whereNotNull('customer_id')->distinct()->pluck('customer_id')->map(fn ($id) => (int) $id)->all();
            $filterCustomers = array_values(array_filter($filterCustomers, fn (array $c) => in_array($c['id'], $reachable, true)));
        }

        return Inertia::render('Survey/Index', [
            'surveys' => $surveys->through(fn (TicketSurvey $survey) => [
                ...$survey->only(['id', 'ticket_ulid', 'ticket_no', 'ticket_title', 'score', 'comment', 'answered_name']),
                'customer' => $customerNames[$survey->customer_id] ?? null,
                'assignee' => $technicians[$survey->assignee_id] ?? null,
                'answered_at' => $survey->answered_at?->toIso8601String(),
                'created_at' => $survey->created_at->toIso8601String(),
            ]),
            'summary' => $summarise->handle($query),
            'filters' => $filters,
            'statuses' => SearchTicketSurveys::STATUSES,
            'customers' => $filterCustomers,
            'technicians' => collect($technicians)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->sortBy('name')->values(),
            'can' => ['viewTickets' => $modules->enabled('service') && $request->user()->can('tickets.view')],
        ]);
    }
}
