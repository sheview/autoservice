<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\SearchCheckouts;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Asset\Support\CheckoutRow;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Contract\Support\ContractPhase;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\SearchPartCheckouts;
use App\Modules\Inventory\Actions\SearchPurchaseRequests;
use App\Modules\Inventory\Models\PartCheckout;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Support\PartCheckoutRow;
use App\Modules\Inventory\Support\PurchaseRequestRow;
use App\Modules\Platform\Support\Modules;
use App\Modules\Reporting\Actions\CountSummaryRows;
use App\Modules\Reporting\Actions\SummarizePeople;
use App\Modules\Reporting\Actions\SummarizeProjects;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What each person, and each project (MA contract), has been issued, lent or bought. Office staff
 * with report.view; each list shows only the forms the user may see in their own module.
 */
class SummaryController extends Controller
{
    public function __construct(
        private Modules $modules,
        private CountSummaryRows $countRows,
        private ContractLabels $contractLabels,
        private SearchCheckouts $searchCheckouts,
        private SearchPurchaseRequests $searchPurchases,
        private SearchPartCheckouts $searchPartCheckouts,
    ) {}

    public function people(Request $request, SummarizePeople $summarize): Response
    {
        abort_unless($request->user()->can(ReportController::PERMISSION), 403);
        $filters = SummarizePeople::filtersFrom($request);

        return Inertia::render('Reporting/People/Index', [
            'people' => $summarize->handle($request->user(), $filters),
            'filters' => $filters,
            'kinds' => $this->kinds(),
        ]);
    }

    /**
     * One person: ?user= a user of the company, or ?name= someone from outside (loans and issues only).
     */
    public function person(Request $request, UserNames $userNames): Response
    {
        $viewer = $request->user();
        abort_unless($viewer->can(ReportController::PERMISSION), 403);

        $userId = $request->integer('user') ?: null;
        $outsideName = $userId ? null : $request->string('name')->trim()->limit(255, '')->value();
        $name = $userId ? ($userNames->handle([$userId])[$userId] ?? null) : $outsideName;
        abort_if(blank($name), 404);

        $filters = $this->listFilters($request);

        return Inertia::render('Reporting/People/Show', [
            'person' => ['user_id' => $userId, 'outside_name' => $outsideName, 'name' => $name],
            'totals' => $this->countRows->handle($viewer, $userId ? ['user_id' => $userId] : ['outside_name' => $outsideName]),
            'filters' => $filters,
            'checkouts' => $this->modules->enabled('asset')
                ? $this->checkouts($viewer, [...$filters, 'borrower_user_id' => $userId, 'borrower_name' => $outsideName])
                : null,
            'partCheckouts' => $this->modules->enabled('inventory')
                ? $this->partCheckouts([...$filters, 'borrower_user_id' => $userId, 'borrower_name' => $outsideName])
                : null,
            // People from outside cannot ask to buy.
            'purchases' => $userId && $this->modules->enabled('inventory')
                ? $this->purchases($viewer, [...$filters, 'requested_by' => $userId])
                : null,
        ]);
    }

    public function projects(Request $request, SummarizeProjects $summarize, ListCustomers $listCustomers): Response
    {
        abort_unless($request->user()->can(ReportController::PERMISSION), 403);
        abort_unless($this->modules->enabled('contract'), 404);
        $filters = SummarizeProjects::filtersFrom($request);

        return Inertia::render('Reporting/Projects/Index', [
            'projects' => $summarize->handle($request->user(), $filters),
            'filters' => $filters,
            'customers' => $listCustomers->handle(),
            'phases' => ContractPhase::PHASES,
        ]);
    }

    public function project(Request $request, int $contract, ContractDetails $contractDetails): Response
    {
        $viewer = $request->user();
        abort_unless($viewer->can(ReportController::PERMISSION), 403);
        abort_unless($this->modules->enabled('contract'), 404);

        $details = $contractDetails->handle([$contract])[$contract] ?? abort(404);
        $filters = $this->listFilters($request);

        return Inertia::render('Reporting/Projects/Show', [
            'project' => collect($details)->only(['id', 'contract_no', 'title', 'status', 'customer', 'starts_on', 'ends_on'])->all(),
            'totals' => $this->countRows->handle($viewer, ['contract_id' => $contract]),
            'filters' => $filters,
            'checkouts' => $this->modules->enabled('asset') ? $this->checkouts($viewer, [...$filters, 'contract_id' => $contract]) : null,
            'partCheckouts' => $this->modules->enabled('inventory') ? $this->partCheckouts([...$filters, 'contract_id' => $contract]) : null,
            'purchases' => $this->modules->enabled('inventory') ? $this->purchases($viewer, [...$filters, 'contract_id' => $contract]) : null,
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
     * @param  array<string, mixed>  $filters
     */
    private function checkouts(User $viewer, array $filters): LengthAwarePaginator
    {
        $page = $this->searchCheckouts->handle($viewer, [...$filters, 'type' => null, 'sort' => 'created_at'])
            ->paginate(10, pageName: 'checkouts_page')->withQueryString();
        $labels = $this->contractLabels->handle($page->getCollection()->pluck('contract_id')->all());

        return $page->through(fn (AssetCheckout $checkout) => [...CheckoutRow::of($checkout), 'contract' => $labels[$checkout->contract_id] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function partCheckouts(array $filters): LengthAwarePaginator
    {
        $page = $this->searchPartCheckouts->handle([...$filters, 'type' => null, 'sort' => 'created_at'])
            ->paginate(10, pageName: 'parts_page')->withQueryString();
        $labels = $this->contractLabels->handle($page->getCollection()->pluck('contract_id')->all());

        return $page->through(fn (PartCheckout $checkout) => [...PartCheckoutRow::of($checkout), 'contract' => $labels[$checkout->contract_id] ?? null]);
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
