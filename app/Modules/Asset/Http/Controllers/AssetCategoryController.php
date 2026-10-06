<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\DeleteAssetCategory;
use App\Modules\Asset\Actions\SaveAssetCategory;
use App\Modules\Asset\Http\Requests\AssetCategoryRequest;
use App\Modules\Asset\Models\AssetCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AssetCategoryController extends Controller
{
    private const SORTABLE = ['name', 'code_prefix', 'assets_count', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AssetCategory::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'service_line' => in_array($request->input('service_line'), AssetCategory::SERVICE_LINES, true) ? $request->input('service_line') : null,
            'asset_type' => in_array($request->input('asset_type'), AssetCategory::ASSET_TYPES, true) ? $request->input('asset_type') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];

        $categories = AssetCategory::query()
            ->withCount('assets')
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$filters['search']}%")
                ->orWhere('code_prefix', 'like', "%{$filters['search']}%")))
            ->when($filters['service_line'], fn ($q, $line) => $q->where('service_line', $line))
            ->when($filters['asset_type'], fn ($q, $type) => $q->where('asset_type', $type))
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AssetCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'code_prefix' => $category->code_prefix,
                'service_line' => $category->service_line,
                'asset_type' => $category->asset_type,
                'spec_fields_count' => count($category->spec_fields),
                'assets_count' => $category->assets_count,
            ]);

        $user = $request->user();

        return Inertia::render('Asset/Categories/Index', [
            'categories' => $categories,
            'filters' => $filters,
            'serviceLines' => AssetCategory::SERVICE_LINES,
            'assetTypes' => AssetCategory::ASSET_TYPES,
            'can' => [
                'create' => $user->can('create', AssetCategory::class),
                'update' => $user->can('asset-categories.manage'),
                'delete' => $user->can('asset-categories.manage'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', AssetCategory::class);

        return Inertia::render('Asset/Categories/Form', $this->formProps(null));
    }

    public function store(AssetCategoryRequest $request, SaveAssetCategory $saveCategory): RedirectResponse
    {
        $saveCategory->handle(null, $request->validated());

        return redirect()->route('asset.categories.index')->with('success', __('asset.categories.created'));
    }

    public function edit(AssetCategory $category): Response
    {
        Gate::authorize('update', $category);

        return Inertia::render('Asset/Categories/Form', $this->formProps($category));
    }

    public function update(AssetCategoryRequest $request, AssetCategory $category, SaveAssetCategory $saveCategory): RedirectResponse
    {
        $saveCategory->handle($category, $request->validated());

        return redirect()->route('asset.categories.index')->with('success', __('asset.categories.updated'));
    }

    public function destroy(AssetCategory $category, DeleteAssetCategory $deleteCategory): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $deleteCategory->handle($category);

        return redirect()->route('asset.categories.index')->with('success', __('asset.categories.deleted'));
    }

    private function formProps(?AssetCategory $category): array
    {
        return [
            'category' => $category?->only(['id', 'name', 'code_prefix', 'service_line', 'asset_type', 'requires_serial', 'spec_fields']),
            'serviceLines' => AssetCategory::SERVICE_LINES,
            'assetTypes' => AssetCategory::ASSET_TYPES,
            'fieldTypes' => AssetCategory::FIELD_TYPES,
        ];
    }
}
