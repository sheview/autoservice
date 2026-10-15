<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetImportsFor;
use App\Modules\Asset\Actions\CategoryIdsByPrefix;
use App\Modules\Asset\Actions\CategoryNames;
use App\Modules\Asset\Actions\ImportAssets;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Maintenance\Actions\StartPmPlanImport;
use App\Modules\Maintenance\Exports\PmEquipmentTemplate;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "นำเข้าอุปกรณ์ (Excel)" of a PM plan: the template, the upload (StartPmPlanImport, in the
 * background) and the plan's imports with their results. Who may change the plan and import assets.
 */
class PmPlanImportController extends Controller
{
    public function show(Request $request, PmPlan $plan, AssetImportsFor $imports, ListCustomers $listCustomers): Response
    {
        $this->authorizeImport($request, $plan);

        return Inertia::render('Maintenance/Plans/Import', [
            'plan' => [
                ...$plan->only(['id', 'title']),
                'customer' => collect($listCustomers->handle(withTrashed: true))->firstWhere('id', $plan->customer_id)['name'] ?? null,
                'assets_count' => $plan->assets()->count(),
            ],
            'imports' => $imports->handle('pm_plan_id', $plan->id),
            'forms' => $this->forms(),
            'maxRows' => ImportAssets::MAX_ROWS,
        ]);
    }

    public function store(Request $request, PmPlan $plan, StartPmPlanImport $start): RedirectResponse
    {
        $this->authorizeImport($request, $plan);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']], [], ['file' => __('asset.imports.file')]);

        $start->handle($plan, $request->user(), $data['file']);

        return to_route('maintenance.plans.import', $plan)->with('success', __('maintenance.plans.import_queued'));
    }

    public function template(Request $request, PmPlan $plan, CategoryIdsByPrefix $prefixes, CategoryNames $names): BinaryFileResponse
    {
        $this->authorizeImport($request, $plan);
        $categoryNames = $names->handle();
        $categories = array_filter(array_map(fn (int $id) => $categoryNames[$id] ?? null, $prefixes->handle()));

        return Excel::download(new PmEquipmentTemplate($categories, $this->forms()), 'pm-equipment-template.xlsx');
    }

    /**
     * The company's published PM forms (newest version of each code).
     *
     * @return list<array{code: string, name: string, layout: string}>
     */
    private function forms(): array
    {
        return PmChecklist::query()->where('status', PmChecklist::STATUS_PUBLISHED)->orderBy('code')->get(['code', 'name', 'layout'])
            ->unique('code')->map(fn (PmChecklist $form) => $form->only(['code', 'name', 'layout']))->values()->all();
    }

    private function authorizeImport(Request $request, PmPlan $plan): void
    {
        Gate::authorize('update', $plan);
        abort_unless($request->user()->can('assets.import'), 403);
    }
}
