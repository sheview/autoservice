<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\DeleteAsset;
use App\Modules\Asset\Actions\SaveAsset;
use App\Modules\Asset\Actions\SearchAssets;
use App\Modules\Asset\Exports\AssetsExport;
use App\Modules\Asset\Http\Requests\AssetRequest;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Support\Money;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetController extends Controller
{
    public function index(Request $request, SearchAssets $search): Response
    {
        Gate::authorize('viewAny', Asset::class);

        $filters = SearchAssets::filtersFrom($request);
        $user = $request->user();

        $assets = $search->handle($user, $filters)
            ->with(['category:id,name', 'branch:id,name'])
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Asset $asset) => [
                'ulid' => $asset->ulid,
                'asset_code' => $asset->asset_code,
                'name' => $asset->name,
                'brand_model' => trim("{$asset->brand} {$asset->model}") ?: null,
                'serial_number' => $asset->serial_number,
                'category' => $asset->category?->name,
                'branch' => $asset->branch?->name,
                'status' => $asset->status,
                'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
            ]);

        return Inertia::render('Asset/Assets/Index', [
            'assets' => $assets,
            'filters' => $filters,
            'branches' => $this->branchOptions($user),
            'categories' => AssetCategory::orderBy('name')->get(['id', 'name']),
            'statuses' => Asset::STATUSES,
            'expiringDays' => SearchAssets::EXPIRING_DAYS,
            'can' => [
                'create' => $user->can('create', Asset::class),
                'import' => $user->can('import', Asset::class),
                'export' => $user->can('export', Asset::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Asset::class);

        return Inertia::render('Asset/Assets/Form', $this->formProps($request->user(), null));
    }

    public function store(AssetRequest $request, SaveAsset $saveAsset): RedirectResponse
    {
        $asset = $saveAsset->handle(null, $request->assetData());

        return redirect()->route('asset.assets.show', $asset)->with('success', __('asset.assets.created', ['code' => $asset->asset_code]));
    }

    public function show(Request $request, Asset $asset): Response
    {
        Gate::authorize('view', $asset);

        $asset->load(['category', 'branch:id,name']);
        $user = $request->user();

        return Inertia::render('Asset/Assets/Show', [
            'asset' => [
                ...$asset->only(['ulid', 'asset_code', 'name', 'brand', 'model', 'serial_number', 'status', 'location', 'notes']),
                'category' => $asset->category?->name,
                'branch' => $asset->branch?->name,
                'purchased_at' => $asset->purchased_at?->toDateString(),
                'purchase_price' => Money::toBaht($asset->purchase_price),
                'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
                'specs' => collect($asset->category?->spec_fields ?? [])
                    ->map(fn (array $field) => ['label' => $field['label'], 'value' => $asset->specs[$field['key']] ?? null])
                    ->values(),
            ],
            'history' => $asset->activities()->latest('id')->limit(20)->get()->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'actor' => $log->properties['actor']['name'] ?? null,
                'changed' => array_keys($log->properties['attributes'] ?? []),
                'at' => $log->created_at->toIso8601String(),
            ]),
            'can' => [
                'update' => $user->can('update', $asset),
                'delete' => $user->can('delete', $asset),
            ],
        ]);
    }

    public function edit(Request $request, Asset $asset): Response
    {
        Gate::authorize('update', $asset);

        return Inertia::render('Asset/Assets/Form', $this->formProps($request->user(), $asset));
    }

    public function update(AssetRequest $request, Asset $asset, SaveAsset $saveAsset): RedirectResponse
    {
        $saveAsset->handle($asset, $request->assetData());

        return redirect()->route('asset.assets.show', $asset)->with('success', __('asset.assets.updated'));
    }

    public function destroy(Asset $asset, DeleteAsset $deleteAsset): RedirectResponse
    {
        Gate::authorize('delete', $asset);

        $deleteAsset->handle($asset);

        return redirect()->route('asset.assets.index')->with('success', __('asset.assets.deleted', ['code' => $asset->asset_code]));
    }

    /**
     * The assets of the list (same filters) as Excel.
     */
    public function export(Request $request, SearchAssets $search): BinaryFileResponse
    {
        Gate::authorize('export', Asset::class);

        $query = $search->handle($request->user(), SearchAssets::filtersFrom($request));

        return Excel::download(new AssetsExport($query, $this->specFieldLabels()), 'assets-'.now()->format('Ymd-His').'.xlsx');
    }

    /**
     * An empty sheet with the import headings.
     */
    public function template(): BinaryFileResponse
    {
        Gate::authorize('import', Asset::class);

        return Excel::download(new AssetsExport(Asset::query()->whereRaw('false'), $this->specFieldLabels()), 'asset-import-template.xlsx');
    }

    /**
     * Spec fields of every category (key => label), one Excel column each.
     *
     * @return array<string, string>
     */
    private function specFieldLabels(): array
    {
        return AssetCategory::orderBy('name')->get(['spec_fields'])
            ->flatMap(fn (AssetCategory $category) => $category->spec_fields)
            ->unique('key')
            ->mapWithKeys(fn (array $field) => [$field['key'] => $field['label']])
            ->all();
    }

    private function formProps(User $user, ?Asset $asset): array
    {
        return [
            'asset' => $asset ? [
                ...$asset->only(['ulid', 'asset_code', 'name', 'category_id', 'branch_id', 'brand', 'model', 'serial_number', 'status', 'location', 'notes', 'specs']),
                'purchased_at' => $asset->purchased_at?->toDateString(),
                'purchase_price' => Money::toBaht($asset->purchase_price),
                'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
            ] : null,
            'categories' => AssetCategory::orderBy('name')->get(['id', 'name', 'code_prefix', 'spec_fields']),
            'branches' => $this->branchOptions($user),
            'statuses' => Asset::STATUSES,
        ];
    }

    /**
     * Branches the user may pick: all of them with branch.all, otherwise only their own.
     */
    private function branchOptions(User $user): array
    {
        return Branch::query()
            ->unless($user->can(PermissionCatalog::ALL_BRANCHES), fn ($q) => $q->whereKey($user->branch_id))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
    }
}
