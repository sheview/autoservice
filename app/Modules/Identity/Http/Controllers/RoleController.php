<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\SaveRole;
use App\Modules\Identity\Actions\SaveRoleMatrix;
use App\Modules\Identity\Actions\SyncRoleGrants;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "บทบาทและสิทธิ์": every role of the company as a column of the permissions matrix (tick a
 * permission, choose its scope), saved together. New roles get a name and label here, then their
 * permissions in the matrix. All of it needs roles.manage.
 */
class RoleController extends Controller
{
    public function index(Request $request, SyncRoleGrants $syncGrants): Response
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()->withCount('users')->orderByRaw('is_system desc')->orderBy('id')->get();

        $resources = [];
        foreach (PermissionCatalog::PERMISSIONS as $resource => $actions) {
            if ($resource !== 'platform') {
                $resources[] = ['key' => $resource, 'actions' => $actions];
            }
        }

        return Inertia::render('Identity/Roles/Index', [
            'roles' => $roles->map(fn (Role $role) => [
                ...$role->only(['id', 'name', 'label', 'is_system', 'users_count']),
                'locked' => $role->name === PermissionCatalog::ADMIN_ROLE,
                'external' => $role->name === PermissionCatalog::CUSTOMER_ROLE,
            ])->values(),
            'grants' => $roles->mapWithKeys(fn (Role $role) => [$role->id => (object) $syncGrants->grantsOf($role)]),
            'resources' => $resources,
            'scopes' => PermissionCatalog::SCOPES,
        ]);
    }

    /**
     * The whole matrix at once: { matrix: { roleId: { permission: scope } } } for every role but
     * the admin's.
     */
    public function matrix(Request $request, SaveRoleMatrix $save): RedirectResponse
    {
        Gate::authorize('viewAny', Role::class);
        abort_unless($request->user()->can('roles.manage'), 403);

        $data = $request->validate([
            'matrix' => ['present', 'array'],
            'matrix.*' => ['array'],
            'matrix.*.*' => ['string'],
        ]);

        $save->handle($data['matrix'], $request->user());

        return back()->with('success', __('ui.roles.saved'));
    }

    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('Identity/Roles/Form', ['role' => null]);
    }

    public function store(RoleRequest $request, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle(null, $request->validated());

        return redirect()->route('identity.roles.index')->with('success', __('identity.roles.created'));
    }

    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        return Inertia::render('Identity/Roles/Form', [
            'role' => $role->only(['id', 'name', 'label', 'is_system']),
        ]);
    }

    public function update(RoleRequest $request, Role $role, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle($role, $request->validated());

        return redirect()->route('identity.roles.index')->with('success', __('identity.roles.updated'));
    }
}
