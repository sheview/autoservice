<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\SaveRole;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $search = $request->string('search')->trim()->value();

        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('label', 'ilike', "%{$search}%")))
            ->orderBy('label')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Role $role) => $role->only(['id', 'name', 'label', 'is_system', 'users_count', 'permissions_count']));

        return Inertia::render('Identity/Roles/Index', [
            'roles' => $roles,
            'filters' => ['search' => $search],
            'can' => ['create' => $request->user()->can('create', Role::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('Identity/Roles/Form', $this->formProps(null));
    }

    public function store(RoleRequest $request, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle(null, $request->validated() + ['permissions' => []]);

        return redirect()->route('identity.roles.index')->with('success', __('identity.roles.created'));
    }

    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        return Inertia::render('Identity/Roles/Form', $this->formProps($role));
    }

    public function update(RoleRequest $request, Role $role, SaveRole $saveRole): RedirectResponse
    {
        $saveRole->handle($role, $request->validated() + ['permissions' => []]);

        return redirect()->route('identity.roles.index')->with('success', __('identity.roles.updated'));
    }

    private function formProps(?Role $role): array
    {
        $groups = [];
        foreach (PermissionCatalog::tenantPermissions() as $name) {
            $groups[strtok($name, '.')][] = $name;
        }

        return [
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'is_system' => $role->is_system,
                'permissions' => $role->permissions->pluck('name'),
            ] : null,
            'permissionGroups' => $groups,
        ];
    }
}
