<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\CategoryNames;
use App\Modules\Maintenance\Actions\SaveChecklist;
use App\Modules\Maintenance\Actions\SearchChecklists;
use App\Modules\Maintenance\Http\Requests\PmChecklistRequest;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Platform\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PmChecklistController extends Controller
{
    public function __construct(private CategoryNames $categoryNames, private Modules $modules) {}

    public function index(Request $request, SearchChecklists $search): Response
    {
        Gate::authorize('viewAny', PmChecklist::class);

        $filters = SearchChecklists::filtersFrom($request);
        $categories = $this->categories(withTrashed: true);
        $user = $request->user();

        return Inertia::render('Maintenance/Checklists/Index', [
            'checklists' => $search->handle($filters)->paginate(20)->withQueryString()->through(fn (PmChecklist $checklist) => [
                'id' => $checklist->id,
                'name' => $checklist->name,
                'category' => $checklist->asset_category_id ? ($categories[$checklist->asset_category_id] ?? null) : null,
                'items_count' => count($checklist->items),
                'updated_at' => $checklist->updated_at->toIso8601String(),
            ]),
            'filters' => $filters,
            'categories' => $this->options($this->categories()),
            'can' => [
                'create' => $user->can('create', PmChecklist::class),
                'update' => $user->can('pm-checklists.manage'),
                'delete' => $user->can('pm-checklists.manage'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PmChecklist::class);

        return Inertia::render('Maintenance/Checklists/Form', $this->formProps(null));
    }

    public function store(PmChecklistRequest $request, SaveChecklist $saveChecklist): RedirectResponse
    {
        $saveChecklist->handle(null, $request->validated());

        return redirect()->route('maintenance.checklists.index')->with('success', __('maintenance.checklists.created'));
    }

    public function edit(PmChecklist $checklist): Response
    {
        Gate::authorize('update', $checklist);

        return Inertia::render('Maintenance/Checklists/Form', $this->formProps($checklist));
    }

    public function update(PmChecklistRequest $request, PmChecklist $checklist, SaveChecklist $saveChecklist): RedirectResponse
    {
        $saveChecklist->handle($checklist, $request->validated());

        return redirect()->route('maintenance.checklists.index')->with('success', __('maintenance.checklists.updated'));
    }

    /**
     * Rounds already started keep their copy of the items, so deleting is always safe.
     */
    public function destroy(PmChecklist $checklist): RedirectResponse
    {
        Gate::authorize('delete', $checklist);

        $checklist->delete();

        return redirect()->route('maintenance.checklists.index')->with('success', __('maintenance.checklists.deleted'));
    }

    private function formProps(?PmChecklist $checklist): array
    {
        return [
            'checklist' => $checklist?->only(['id', 'name', 'asset_category_id', 'items']),
            'categories' => $this->options($this->categories()),
            'itemTypes' => PmChecklist::ITEM_TYPES,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function categories(bool $withTrashed = false): array
    {
        return $this->modules->enabled('asset') ? $this->categoryNames->handle($withTrashed) : [];
    }

    /**
     * @param  array<int, string>  $names
     * @return list<array{id: int, name: string}>
     */
    private function options(array $names): array
    {
        return collect($names)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values()->all();
    }
}
