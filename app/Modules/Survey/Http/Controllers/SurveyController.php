<?php

namespace App\Modules\Survey\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
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
        $query = $search->handle($filters);
        $surveys = (clone $query)->paginate(20)->withQueryString();

        $customers = $modules->enabled('contract') ? $listCustomers->handle(withTrashed: true) : [];
        $customerNames = collect($customers)->pluck('name', 'id');
        // Every technician who has a survey, for the filter (and the names of this page).
        $technicians = $userNames->handle(TicketSurvey::query()->whereNotNull('assignee_id')->distinct()->pluck('assignee_id')->all());

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
            'customers' => $modules->enabled('contract') ? $listCustomers->handle() : [],
            'technicians' => collect($technicians)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->sortBy('name')->values(),
            'can' => ['viewTickets' => $modules->enabled('service') && $request->user()->can('ticket.view')],
        ]);
    }
}
