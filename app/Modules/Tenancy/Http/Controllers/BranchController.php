<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Tenancy\Actions\DeleteBranch;
use App\Modules\Tenancy\Actions\SaveBranch;
use App\Modules\Tenancy\Http\Requests\BranchRequest;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's branches (offices), which assets, users and tickets belong to.
 */
class BranchController extends Controller
{
    private const SORTABLE = ['code', 'name', 'province', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Branch::class);

        $user = $request->user();
        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'province' => $request->string('province')->trim()->value() ?: null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'code',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];

        // A user limited to their branch sees only that branch.
        $visible = fn () => DataScope::constrain(Branch::query(), $user, 'branches.view', fn ($q, $id) => $q->whereKey($id), null);

        $branches = $visible()
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'like', "%{$filters['search']}%")
                ->orWhere('name', 'like', "%{$filters['search']}%")
                ->orWhere('address', 'like', "%{$filters['search']}%")))
            ->when($filters['province'], fn ($q, $province) => $q->where('province', $province))
            ->orderBy($filters['sort'], $filters['direction'])
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Branch $branch) => $branch->only(['id', 'code', 'name', 'address', 'province']));

        return Inertia::render('Tenancy/Branches/Index', [
            'branches' => $branches,
            'filters' => $filters,
            'provinces' => $visible()->whereNotNull('province')->distinct()->orderBy('province')->pluck('province'),
            'can' => [
                'create' => $user->can('create', Branch::class),
                'update' => $user->can('branches.manage'),
                'delete' => $user->can('branches.manage'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Branch::class);

        return Inertia::render('Tenancy/Branches/Form', ['branch' => null]);
    }

    public function store(BranchRequest $request, SaveBranch $saveBranch): RedirectResponse
    {
        $saveBranch->handle(null, $request->validated());

        return redirect()->route('tenancy.branches.index')->with('success', __('tenancy.branches.created'));
    }

    public function edit(Branch $branch): Response
    {
        Gate::authorize('update', $branch);

        return Inertia::render('Tenancy/Branches/Form', ['branch' => $branch->only(['id', 'code', 'name', 'address', 'province'])]);
    }

    public function update(BranchRequest $request, Branch $branch, SaveBranch $saveBranch): RedirectResponse
    {
        $saveBranch->handle($branch, $request->validated());

        return redirect()->route('tenancy.branches.index')->with('success', __('tenancy.branches.updated'));
    }

    public function destroy(Branch $branch, DeleteBranch $deleteBranch): RedirectResponse
    {
        Gate::authorize('delete', $branch);

        $deleteBranch->handle($branch);

        return redirect()->route('tenancy.branches.index')->with('success', __('tenancy.branches.deleted'));
    }
}
