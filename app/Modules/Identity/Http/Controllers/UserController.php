<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\SaveUser;
use App\Modules\Identity\Http\Requests\UserRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\CrossTenant\LinkedAccounts;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const SORTABLE = ['name', 'email', 'employee_code', 'created_at'];

    public function __construct(
        private Modules $modules,
        private ListCustomers $listCustomers,
    ) {}

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

        $customerNames = collect($this->customerOptions(withTrashed: true))->pluck('name', 'id');
        $viewer = $request->user();

        // Within the viewer's users.view: their branch (and users of none), or only themself.
        $users = DataScope::constrain(User::query(), $viewer, 'users.view', customer: null,
            own: fn ($q) => $q->whereKey($viewer->id))
            ->with(['branch:id,name', 'roles:id,name,label'])
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$filters['search']}%")
                ->orWhere('email', 'like', "%{$filters['search']}%")
                ->orWhere('employee_code', 'like', "%{$filters['search']}%")))
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
                'customer' => $customerNames[$user->customer_id] ?? null,
                'role' => $user->roles->first()?->label,
                'is_active' => $user->is_active,
                'linked' => $user->login_user_id !== null,
            ]);

        return Inertia::render('Identity/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'branches' => $this->branchOptions(),
            'roles' => $this->roleOptions(),
            'can' => ['create' => $viewer->can('create', User::class)],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Identity/Users/Form', $this->formProps(null));
    }

    public function store(UserRequest $request, SaveUser $saveUser): RedirectResponse
    {
        $saveUser->handle(null, [...$request->validated(), 'login_user_id' => $request->loginUserId()]);

        return redirect()->route('identity.users.index')->with('success', __('identity.users.created'));
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('Identity/Users/Form', $this->formProps($user));
    }

    public function update(UserRequest $request, User $user, SaveUser $saveUser): RedirectResponse
    {
        $saveUser->handle($user, [...$request->validated(), 'login_user_id' => $request->loginUserId()]);

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
                'customer_id' => $user->customer_id,
                'employee_code' => $user->employee_code,
                'position' => $user->position,
                'phone' => $user->phone,
                'service_lines' => $user->service_lines,
                'is_active' => $user->is_active,
                'role' => $user->roles->first()?->name,
                'main_email' => $user->login_user_id ? app(LinkedAccounts::class)->emailOf($user->login_user_id) : null,
            ] : null,
            'branches' => $this->branchOptions(),
            'roles' => $this->roleOptions(),
            'customers' => $this->customerOptions(),
            'customerRole' => PermissionCatalog::CUSTOMER_ROLE,
            'serviceLines' => User::SERVICE_LINES,
        ];
    }

    /**
     * The branches a user can be put in: with users.manage scope branch, only the manager's own.
     */
    private function branchOptions(): array
    {
        $manager = request()->user();
        $ownBranchOnly = $manager !== null && DataScope::of($manager, 'users.manage') === PermissionCatalog::SCOPE_BRANCH;

        return Branch::orderBy('name')
            ->when($ownBranchOnly, fn ($q) => $q->whereKey($manager->branch_id))
            ->get(['id', 'name'])->toArray();
    }

    /**
     * Customers (Contract module) a customer account can belong to; none when the module is off.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function customerOptions(bool $withTrashed = false): array
    {
        return $this->modules->enabled('contract') ? $this->listCustomers->handle($withTrashed) : [];
    }

    private function roleOptions(): array
    {
        return Role::orderBy('label')->get(['name', 'label'])->toArray();
    }
}
