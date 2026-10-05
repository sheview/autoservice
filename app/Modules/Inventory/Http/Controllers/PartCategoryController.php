<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\DeletePartCategory;
use App\Modules\Inventory\Actions\SavePartCategory;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCategory;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Part categories and whether their new parts are followed by serial number: parts.serials.
 */
class PartCategoryController extends Controller
{
    public const PERMISSION = 'parts.serials';

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $counts = Part::query()->whereNotNull('part_category_id')->selectRaw('part_category_id, count(*) as parts')
            ->groupBy('part_category_id')->pluck('parts', 'part_category_id');

        return Inertia::render('Inventory/Categories/Index', [
            'categories' => PartCategory::query()->orderBy('name')->get()->map(fn (PartCategory $category) => [
                ...$category->only(['id', 'name', 'track_serial']),
                'parts' => (int) ($counts[$category->id] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request, SavePartCategory $save): RedirectResponse
    {
        $this->authorizeManage($request);
        $save->handle(null, $this->validated($request, null));

        return back()->with('success', __('inventory.categories.created'));
    }

    public function update(Request $request, PartCategory $category, SavePartCategory $save): RedirectResponse
    {
        $this->authorizeManage($request);
        $save->handle($category, $this->validated($request, $category));

        return back()->with('success', __('inventory.categories.updated'));
    }

    public function destroy(Request $request, PartCategory $category, DeletePartCategory $delete): RedirectResponse
    {
        $this->authorizeManage($request);
        $delete->handle($category);

        return back()->with('success', __('inventory.categories.deleted'));
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('viewAny', Part::class) && $request->user()->can(self::PERMISSION), 403);
    }

    /**
     * @return array{name: string, track_serial: bool}
     */
    private function validated(Request $request, ?PartCategory $category): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($category) {
                $taken = PartCategory::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists();
                if ($taken) {
                    $fail(__('inventory.categories.taken'));
                }
            }],
            'track_serial' => ['boolean'],
        ], [], ['name' => __('inventory.fields.part_category_id')]);
    }
}
