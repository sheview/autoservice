<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\SyncContractMembers;
use App\Modules\Contract\Models\Contract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContractMemberController extends Controller
{
    /** Sets the project's team (whoever may change the contract). */
    public function update(Request $request, Contract $contract, SyncContractMembers $syncMembers): RedirectResponse
    {
        Gate::authorize('update', $contract);

        $validated = $request->validate([
            'user_ids' => ['present', 'array', 'max:200'],
            'user_ids.*' => ['integer'],
        ]);

        $syncMembers->handle($contract, $validated['user_ids']);

        return back()->with('success', __('contract.members.saved'));
    }
}
