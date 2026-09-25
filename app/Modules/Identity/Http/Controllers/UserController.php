<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\SaveUser;
use App\Modules\Identity\Http\Requests\UserRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const SORTABLE = ['name', 'email', 'employee_code', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'branch_id' => $request->integer('branch_id') ?: null,
            'role' => $request->string('role')->value() ?: null,
            'status' => $request->string('status')->value() ?: null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'name',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];

        $users = User::query()
            ->with(['branch:id,name', 'roles:id,name,label'])
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$filters['search']}%")
                ->orWhere('email', 'ilike', "%{$filters['search']}%")
                ->orWhere('employee_code', 'ilike', "%{$filters['search']}%")))
            ->when($filters['branch_id'], fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['role'], fn ($q, $role) => $q->role($role))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy($filters['sort'], $filters['direction'])
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_code' => $user->employee_code,
                'position' => $user->position,
                'branch' => $user->branch?->name,
                'role' => $user->roles->first()?->label,
                'is_active' => $user->is_active,
            ]);

        return Inertia::render('Identity/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'branches' => $this->branchOptions(),
            'roles' => $this->roleOptions(),
            'can' => ['create' => $request->user()->can('create', User::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Identity/Users/Form', $this->formProps(null));
    }

    public function store(UserRequest $request, SaveUser $saveUser): RedirectResponse
    {
        $saveUser->handle(null, $request->validated());

        return redirect()->route('identity.users.index')->with('success', __('identity.users.created'));
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('Identity/Users/Form', $this->formProps($user));
    }

    public function update(UserRequest $request, User $user, SaveUser $saveUser): RedirectResponse
    {
        $saveUser->handle($user, $request->validated());

        return redirect()->route('identity.users.index')->with('success', __('identity.users.updated'));
    }

    private function formProps(?User $user): array
    {
        return [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'branch_id' => $user->branch_id,
                'employee_code' => $user->employee_code,
                'position' => $user->position,
                'phone' => $user->phone,
                'service_lines' => $user->service_lines,
                'is_active' => $user->is_active,
                'role' => $user->roles->first()?->name,
            ] : null,
            'branches' => $this->branchOptions(),
            'roles' => $this->roleOptions(),
            'serviceLines' => User::SERVICE_LINES,
        ];
    }

    private function branchOptions(): array
    {
        return Branch::orderBy('name')->get(['id', 'name'])->toArray();
    }

    private function roleOptions(): array
    {
        return Role::orderBy('label')->get(['name', 'label'])->toArray();
    }
}
