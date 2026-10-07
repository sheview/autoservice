<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ImportUsers;
use App\Modules\Identity\Exports\UserImportTemplate;
use App\Modules\Identity\Http\Requests\UserImportRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Importing the company's first accounts from Excel (ImportUsers).
 */
class UserImportController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        $user = $request->user();
        $ownBranchOnly = DataScope::of($user, 'users.manage') === PermissionCatalog::SCOPE_BRANCH;

        return Inertia::render('Identity/Users/Import', [
            // The values the role and branch columns take.
            'roles' => Role::orderBy('label')->get(['name', 'label'])->toArray(),
            'branches' => Branch::orderBy('code')
                ->when($ownBranchOnly, fn ($q) => $q->whereKey($user->branch_id))
                ->get(['code', 'name'])->toArray(),
            'customerRole' => PermissionCatalog::CUSTOMER_ROLE,
            'maxRows' => ImportUsers::MAX_ROWS,
            'result' => $request->session()->get('importResult'),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('create', User::class);

        return Excel::download(new UserImportTemplate, 'user-import-template.xlsx');
    }

    public function store(UserImportRequest $request, ImportUsers $importUsers): RedirectResponse
    {
        $result = $importUsers->handle($request->user(), $request->file('file'));

        return redirect()->route('identity.users.import')->with('importResult', $result);
    }
}
