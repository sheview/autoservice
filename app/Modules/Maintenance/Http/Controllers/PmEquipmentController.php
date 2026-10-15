<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\CategoryNames;
use App\Modules\Asset\Actions\PaginateAssets;
use App\Modules\Asset\Actions\SearchAssets;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Maintenance\Actions\PmEquipmentStatus;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipment & Assets: the equipment under PM (on a work order or covered by a planned contract),
 * with its last PM and its next one. The asset list's own search, filters, sort and the user's
 * scope on assets apply (SearchAssets); "pm" narrows to overdue, due soon or never done.
 */
class PmEquipmentController extends Controller
{
    public function index(Request $request, PmEquipmentStatus $equipment, PaginateAssets $paginate, ListCustomers $listCustomers, CategoryNames $categoryNames): Response
    {
        Gate::authorize('viewAny', PmVisit::class);
        Gate::authorize('assets.view');
        abort_if($request->user()->customer_id !== null, 403);

        $filters = SearchAssets::filtersFrom($request);
        $pm = in_array($request->input('pm'), PmEquipmentStatus::FILTERS, true) ? $request->input('pm') : null;

        $ids = $equipment->equipmentIds();
        $status = $equipment->handle($ids);
        if ($pm !== null) {
            $ids = PmEquipmentStatus::matching($status, $pm);
        }

        $assets = $paginate->handle($request->user(), $filters, $ids);
        $customers = collect($listCustomers->handle(withTrashed: true))->pluck('name', 'id');

        return Inertia::render('Maintenance/Equipment/Index', [
            'assets' => $assets->through(fn (array $asset) => [
                ...$asset,
                'customer' => $customers[$asset['customer_id']] ?? null,
                'pm' => $status[$asset['id']] ?? null,
            ]),
            'filters' => [...$filters, 'pm' => $pm],
            'customers' => $listCustomers->handle(),
            'categories' => collect($categoryNames->handle())->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'pmFilters' => PmEquipmentStatus::FILTERS,
            'counts' => collect(PmEquipmentStatus::FILTERS)->mapWithKeys(fn (string $filter) => [$filter => count(PmEquipmentStatus::matching($status, $filter))]),
        ]);
    }
}
