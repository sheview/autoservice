<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\ItemRequestLines;
use App\Modules\Asset\Actions\SearchSummaryLines;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Contract\Support\ContractPhase;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\SearchPurchaseRequests;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PurchaseRequestRow;
use App\Modules\Platform\Support\Modules;
use App\Modules\Reporting\Actions\CountSummaryRows;
use App\Modules\Reporting\Actions\SummarizePeople;
use App\Modules\Reporting\Actions\SummarizeProjects;
use App\Modules\Reporting\Actions\SummarizeTicketKpi;
use App\Modules\Service\Actions\TicketKpi;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What each person (summary-people.view), and each project (MA contract, summary-projects.view),
 * has been issued, lent or bought; each list shows only the forms the user may see in their own
 * module. With scope own a person sees only their own summary; a customer account only the
 * projects of its customer, without purchases or amounts (SummarizePeople, SummarizeProjects).
 */
class SummaryController extends Controller
{
    public function __construct(
        private Modules $modules,
        private CountSummaryRows $countRows,
        private ContractLabels $contractLabels,
        private SearchSummaryLines $searchLines,
        private SearchPurchaseRequests $searchPurchases,
    ) {}

    public function people(Request $request, SummarizePeople $summarize): Response
    {
        abort_unless($request->user()->can(SummarizePeople::PERMISSION), 403);
        $filters = SummarizePeople::filtersFrom($request);

        return Inertia::render('Reporting/People/Index', [
            'people' => $summarize->handle($request->user(), $filters),
            'filters' => $filters,
            'kinds' => $this->kinds(),
            'canKpi' => $this->modules->enabled('service'),
        ]);
    }

    /** Ticket KPI by person for a year: opened, fixed, on time, time to fix. */
    public function kpi(Request $request, SummarizeTicketKpi $summarize): Response
    {
        abort_unless($request->user()->can(SummarizePeople::PERMISSION) && $this->modules->enabled('service'), 403);
        $filters = SummarizeTicketKpi::filtersFrom($request);

        return Inertia::render('Reporting/People/Kpi', [
            'people' => $summarize->handle($request->user(), $filters),
            'filters' => $filters,
            'years' => range((int) now()->year, (int) now()->year - 4),
        ]);
    }

    /**
     * One person: ?user= a user of the company, or ?name= someone from outside (loans and issues only).
     */
    public function person(Request $request, UserNames $userNames, TicketKpi $ticketKpi): Response
    {
        $viewer = $request->user();
        abort_unless($viewer->can(SummarizePeople::PERMISSION), 403);

        $userId = $request->integer('user') ?: null;
        abort_unless(SummarizePeople::reaches($viewer, $userId), 403);
        $outsideName = $userId ? null : $request->string('name')->trim()->limit(255, '')->value();
        $name = $userId ? ($userNames->handle([$userId])[$userId] ?? null) : $outsideName;
        abort_if(blank($name), 404);

        $filters = $this->listFilters($request);

        return Inertia::render('Reporting/People/Show', [
            'person' => ['user_id' => $userId, 'outside_name' => $outsideName, 'name' => $name],
            // Ticket work by month of a year (staff of the company only).
            'kpi' => $userId && $this->modules->enabled('service') ? [
                'year' => $kpiYear = SummarizeTicketKpi::filtersFrom($request)['year'],
                'months' => array_values($ticketKpi->handle($kpiYear, [$userId], byMonth: true)),
                'years' => range((int) now()->year, (int) now()->year - 4),
            ] : null,
            'totals' => $this->countRows->handle($viewer, $userId ? ['user_id' => $userId] : ['outside_name' => $outsideName]),
            'filters' => $filters,
            'checkouts' => $this->modules->enabled('asset')
                ? $this->checkouts($viewer, [...$filters, 'borrower_user_id' => $userId, 'borrower_name' => $outsideName])
                : null,
            'partCheckouts' => $this->modules->enabled('asset') && $this->modules->enabled('inventory')
                ? $this->partCheckouts($viewer, [...$filters, 'borrower_user_id' => $userId, 'borrower_name' => $outsideName])
                : null,
            // People from outside cannot ask to buy.
            'purchases' => $userId && $this->modules->enabled('inventory')
                ? $this->purchases($viewer, [...$filters, 'requested_by' => $userId])
                : null,
        ]);
    }

    public function projects(Request $request, SummarizeProjects $summarize, ListCustomers $listCustomers): Response
    {
        $viewer = $request->user();
        abort_unless($viewer->can(SummarizeProjects::PERMISSION), 403);
        abort_unless($this->modules->enabled('contract'), 404);
        $filters = SummarizeProjects::filtersFrom($request);
        $customerIds = $summarize->customerIds($viewer);

        return Inertia::render('Reporting/Projects/Index', [
            'projects' => $summarize->handle($viewer, $filters),
            'filters' => $filters,
            // Only the customers whose projects the user may see.
            'customers' => collect($listCustomers->handle())
                ->filter(fn (array $customer) => $customerIds === null || in_array($customer['id'], $customerIds, true))
                ->values()->all(),
            'phases' => ContractPhase::PHASES,
            'showsPurchases' => SummarizeProjects::showsPurchases($viewer),
        ]);
    }

    public function project(Request $request, int $contract, ContractDetails $contractDetails, SummarizeProjects $summarize): Response
    {
        $viewer = $request->user();
        abort_unless($viewer->can(SummarizeProjects::PERMISSION), 403);
        abort_unless($this->modules->enabled('contract'), 404);

        $details = $contractDetails->handle([$contract])[$contract] ?? abort(404);
        $customerIds = $summarize->customerIds($viewer);
        abort_unless($customerIds === null || in_array((int) $details['customer_id'], $customerIds, true), 403);

        $filters = $this->listFilters($request);
        $purchases = SummarizeProjects::showsPurchases($viewer);

        return Inertia::render('Reporting/Projects/Show', [
            'project' => collect($details)->only(['id', 'contract_no', 'title', 'status', 'customer', 'starts_on', 'ends_on'])->all(),
            'totals' => $this->countRows->handle($viewer, ['contract_id' => $contract], $purchases),
            'filters' => $filters,
            'checkouts' => $this->modules->enabled('asset') ? $this->checkouts($viewer, [...$filters, 'contract_id' => $contract]) : null,
            'partCheckouts' => $this->modules->enabled('asset') && $this->modules->enabled('inventory') ? $this->partCheckouts($viewer, [...$filters, 'contract_id' => $contract]) : null,
            // Customer accounts never see what was bought, or for how much.
            'purchases' => $purchases && $this->modules->enabled('inventory') ? $this->purchases($viewer, [...$filters, 'contract_id' => $contract]) : null,
            'showsPurchases' => $purchases,
        ]);
    }

    /**
     * Search, open-or-all and newest/oldest first, shared by the two lists of a person or project page.
     *
     * @return array{search: string, status: string, direction: string}
     */
    private function listFilters(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => $request->input('status') === 'open' ? 'open' : 'all',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * The asset lines of the issue/loan requests (ItemRequestLines::row + the project).
     *
     * @param  array<string, mixed>  $filters
     */
    private function checkouts(User $viewer, array $filters): LengthAwarePaginator
    {
        return $this->lines($viewer, $filters, CheckoutItem::TYPE_ASSET, 'checkouts_page');
    }

    /**
     * The part lines of the issue/loan requests (same rows as the assets').
     *
     * @param  array<string, mixed>  $filters
     */
    private function partCheckouts(User $viewer, array $filters): LengthAwarePaginator
    {
        return $this->lines($viewer, $filters, CheckoutItem::TYPE_PART, 'parts_page');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function lines(User $viewer, array $filters, string $itemType, string $pageName): LengthAwarePaginator
    {
        $page = $this->searchLines->handle($viewer, $filters, $itemType)->paginate(10, pageName: $pageName)->withQueryString();
        $labels = $this->contractLabels->handle($page->getCollection()->map(fn (CheckoutItem $item) => $item->request->contract_id)->all());

        return $page->through(fn (CheckoutItem $item) => [...ItemRequestLines::row($item), 'contract' => $labels[$item->request->contract_id] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function purchases(User $viewer, array $filters): LengthAwarePaginator
    {
        $page = $this->searchPurchases->handle($viewer, [...$filters, 'mine' => false, 'sort' => 'created_at'])
            ->paginate(10, pageName: 'purchases_page')->withQueryString();
        $labels = $this->contractLabels->handle($page->getCollection()->pluck('contract_id')->all());

        return $page->through(fn (PurchaseRequest $pr) => [...PurchaseRequestRow::of($pr), 'contract' => $labels[$pr->contract_id] ?? null]);
    }

    /**
     * The kinds the person list can be narrowed to: those of the modules switched on.
     *
     * @return list<string>
     */
    private function kinds(): array
    {
        return array_values(array_filter([
            $this->modules->enabled('asset') ? 'issue' : null,
            $this->modules->enabled('asset') ? 'loan' : null,
            $this->modules->enabled('inventory') ? 'purchase' : null,
        ]));
    }
}
