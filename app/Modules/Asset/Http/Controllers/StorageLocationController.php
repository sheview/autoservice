<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\DeleteStorageLocation;
use App\Modules\Asset\Actions\SaveStorageLocation;
use App\Modules\Asset\Models\StorageLocation;
use App\Modules\Inventory\Actions\PartCountsByLocation;
use App\Modules\Platform\Support\Modules;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Storage locations, the drop-down of where assets and parts are kept (Settings).
 */
class StorageLocationController extends Controller
{
    private const SORTABLE = ['name', 'assets_count', 'created_at'];

    public const STATUSES = ['active', 'inactive'];

    public function index(Request $request, Modules $modules): Response
    {
        Gate::authorize('viewAny', StorageLocation::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];

        $locations = StorageLocation::query()
            ->withCount('assets')
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$filters['search']}%")->orWhere('notes', 'like', "%{$filters['search']}%")))
            ->when($filters['status'], fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $parts = $modules->enabled('inventory') ? app(PartCountsByLocation::class)->handle($locations->pluck('id')->all()) : [];
        $user = $request->user();

        return Inertia::render('Asset/StorageLocations/Index', [
            'locations' => $locations->through(fn (StorageLocation $location) => [
                ...$location->only(['id', 'name', 'notes', 'is_active', 'assets_count']),
                'parts_count' => $parts[$location->id] ?? 0,
            ]),
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'showParts' => $modules->enabled('inventory'),
            'can' => ['manage' => $user->can('create', StorageLocation::class)],
        ]);
    }

    public function store(Request $request, SaveStorageLocation $save): RedirectResponse
    {
        Gate::authorize('create', StorageLocation::class);
        $save->handle(null, $this->validated($request, null));

        return back()->with('success', __('asset.locations.created'));
    }

    public function update(Request $request, StorageLocation $location, SaveStorageLocation $save): RedirectResponse
    {
        Gate::authorize('update', $location);
        $save->handle($location, $this->validated($request, $location));

        return back()->with('success', __('asset.locations.updated'));
    }

    public function destroy(StorageLocation $location, DeleteStorageLocation $delete): RedirectResponse
    {
        Gate::authorize('delete', $location);
        $delete->handle($location);

        return back()->with('success', __('asset.locations.deleted'));
    }

    /**
     * @return array{name: string, notes?: string|null, is_active?: bool}
     */
    private function validated(Request $request, ?StorageLocation $location): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($location) {
                $taken = StorageLocation::query()->where('name', trim((string) $value))
                    ->when($location, fn ($q) => $q->whereKeyNot($location->id))->exists();
                if ($taken) {
                    $fail(__('asset.locations.taken'));
                }
            }],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], [], ['name' => __('asset.locations.name'), 'notes' => __('asset.locations.notes')]);
    }
}
