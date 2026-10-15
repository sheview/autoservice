<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\CatalogOptions;
use App\Modules\Asset\Actions\CategoryNames;
use App\Modules\Maintenance\Actions\AddStandardChecklists;
use App\Modules\Maintenance\Actions\CopyTemplate;
use App\Modules\Maintenance\Actions\CreateTemplateVersion;
use App\Modules\Maintenance\Actions\DeleteTemplateDraft;
use App\Modules\Maintenance\Actions\PublishTemplate;
use App\Modules\Maintenance\Actions\SaveTemplateDraft;
use App\Modules\Maintenance\Actions\SearchChecklists;
use App\Modules\Maintenance\Http\Requests\PmChecklistRequest;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Platform\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PM templates (ตั้งค่า PM → Template): drafts are changed, published versions are only copied
 * into a new draft (CreateTemplateVersion) or another template (CopyTemplate).
 */
class PmChecklistController extends Controller
{
    public function __construct(private CategoryNames $categoryNames, private Modules $modules) {}

    public function index(Request $request, SearchChecklists $search): Response
    {
        Gate::authorize('viewAny', PmChecklist::class);

        $filters = SearchChecklists::filtersFrom($request);
        $categories = $this->categories(withTrashed: true);
        $user = $request->user();
        $page = $search->handle($filters)->paginate(20)->withQueryString();
        $codes = $page->getCollection()->pluck('code')->all();
        $assignments = PmTemplateAssignment::query()->whereIn('template_code', $codes)->whereNull('asset_id')->get()->groupBy('template_code');
        $published = PmChecklist::query()->whereIn('code', $codes)->where('status', PmChecklist::STATUS_PUBLISHED)->pluck('version', 'code');

        return Inertia::render('Maintenance/Checklists/Index', [
            'checklists' => $page->through(fn (PmChecklist $template) => [
                'id' => $template->id,
                'code' => $template->code,
                'name' => $template->name,
                'version' => $template->version,
                'status' => $template->status,
                'published_version' => $published[$template->code] ?? null,
                'service_line' => $template->service_line,
                'layout' => $template->layout,
                'items_count' => count($template->items),
                'tied_to' => $this->tiedTo($assignments[$template->code] ?? collect(), $categories),
                'updated_at' => $template->updated_at->toIso8601String(),
            ]),
            'filters' => $filters,
            'categories' => $this->options($this->categories()),
            'statuses' => PmChecklist::STATUSES,
            'serviceLines' => PmChecklist::SERVICE_LINES,
            'can' => [
                'create' => $user->can('create', PmChecklist::class),
                'update' => $user->can('pm-checklists.manage'),
            ],
            // Standard templates the company does not have yet (names), offered to add in one go.
            'standardMissing' => $user->can('create', PmChecklist::class)
                ? array_column(app(AddStandardChecklists::class)->missing(), 'name')
                : [],
        ]);
    }

    public function addStandard(AddStandardChecklists $addStandard): RedirectResponse
    {
        Gate::authorize('create', PmChecklist::class);

        $added = $addStandard->handle();

        return back()->with('success', __('maintenance.checklists.standard_added', ['count' => $added]));
    }

    public function create(): Response
    {
        Gate::authorize('create', PmChecklist::class);

        return Inertia::render('Maintenance/Checklists/Form', $this->formProps(null));
    }

    public function store(PmChecklistRequest $request, SaveTemplateDraft $save, PublishTemplate $publish): RedirectResponse
    {
        $template = $save->handle(null, $request->templateData());
        if ($request->boolean('publish')) {
            $publish->handle($template);
        }

        return redirect()->route('maintenance.checklists.edit', $template)->with('success', __('maintenance.checklists.created'));
    }

    /**
     * A draft opens to change; a published or archived version opens to read, with its versions.
     */
    public function edit(PmChecklist $checklist): Response
    {
        Gate::authorize('view', $checklist);

        return Inertia::render('Maintenance/Checklists/Form', $this->formProps($checklist));
    }

    public function update(PmChecklistRequest $request, PmChecklist $checklist, SaveTemplateDraft $save, PublishTemplate $publish): RedirectResponse
    {
        $save->handle($checklist, $request->templateData());
        if ($request->boolean('publish')) {
            $publish->handle($checklist);

            return redirect()->route('maintenance.checklists.edit', $checklist)->with('success', __('maintenance.templates.published'));
        }

        return back()->with('success', __('maintenance.checklists.updated'));
    }

    public function publish(PmChecklist $checklist, PublishTemplate $publish): RedirectResponse
    {
        Gate::authorize('update', $checklist);

        $publish->handle($checklist);

        return back()->with('success', __('maintenance.templates.published'));
    }

    public function newVersion(PmChecklist $checklist, CreateTemplateVersion $createVersion): RedirectResponse
    {
        Gate::authorize('update', $checklist);

        return redirect()->route('maintenance.checklists.edit', $createVersion->handle($checklist));
    }

    public function copy(PmChecklist $checklist, CopyTemplate $copy): RedirectResponse
    {
        Gate::authorize('create', PmChecklist::class);

        return redirect()->route('maintenance.checklists.edit', $copy->handle($checklist))
            ->with('success', __('maintenance.templates.copied'));
    }

    /**
     * Only a draft; work already started keeps its copy of the fields.
     */
    public function destroy(PmChecklist $checklist, DeleteTemplateDraft $delete): RedirectResponse
    {
        Gate::authorize('delete', $checklist);

        $delete->handle($checklist);

        return redirect()->route('maintenance.checklists.index')->with('success', __('maintenance.checklists.deleted'));
    }

    private function formProps(?PmChecklist $template): array
    {
        $assetOn = $this->modules->enabled('asset');

        return [
            'checklist' => $template ? [
                ...$template->only(['id', 'code', 'name', 'version', 'status', 'service_line', 'layout', 'items']),
                'blocks' => array_keys(array_filter($template->blocks ?? [])),
                'published_at' => $template->published_at?->toIso8601String(),
                'assignments' => PmTemplateAssignment::query()->where('template_code', $template->code)->whereNull('asset_id')
                    ->orderBy('id')->get(['asset_category_id', 'asset_brand_id', 'asset_model_id']),
            ] : null,
            'versions' => $template
                ? PmChecklist::query()->where('code', $template->code)->orderByDesc('version')->get(['id', 'version', 'status', 'published_at', 'updated_at'])
                : [],
            'categories' => $this->options($this->categories()),
            'catalog' => $assetOn ? app(CatalogOptions::class)->handle() : ['brands' => [], 'models' => []],
            'itemTypes' => ChecklistItems::EDITABLE_TYPES,
            'repeatColumnTypes' => ChecklistItems::REPEAT_COLUMN_TYPES,
            'assetSources' => ChecklistItems::ASSET_SOURCES,
            'layouts' => PmChecklist::LAYOUTS,
            'blocks' => PmChecklist::BLOCKS,
            'serviceLines' => PmChecklist::SERVICE_LINES,
            'limits' => ['rows' => ChecklistItems::MAX_ROWS, 'columns' => ChecklistItems::MAX_COLUMNS, 'items' => ChecklistItems::MAX_ITEMS],
            'can' => ['manage' => request()->user()->can('pm-checklists.manage')],
        ];
    }

    /**
     * What a template is tied to, in words: category names, "brand in category", models, default.
     *
     * @param  Collection<int, PmTemplateAssignment>  $assignments
     * @param  array<int, string>  $categories
     * @return list<string>
     */
    private function tiedTo(Collection $assignments, array $categories): array
    {
        return $assignments->map(function (PmTemplateAssignment $assignment) use ($categories) {
            if ($assignment->asset_model_id || $assignment->asset_brand_id) {
                return __('maintenance.templates.tied_catalog');
            }

            return $assignment->asset_category_id
                ? ($categories[$assignment->asset_category_id] ?? '-')
                : __('maintenance.templates.tied_default');
        })->unique()->values()->all();
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
