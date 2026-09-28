<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\AddContractAssets;
use App\Modules\Contract\Actions\RemoveContractAsset;
use App\Modules\Contract\Models\Contract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContractAssetController extends Controller
{
    public function store(Request $request, Contract $contract, AddContractAssets $addAssets): RedirectResponse
    {
        Gate::authorize('update', $contract);
        Gate::authorize('asset.view');

        $validated = $request->validate([
            'asset_ids' => ['required', 'array', 'max:200'],
            'asset_ids.*' => ['integer'],
        ]);

        $added = $addAssets->handle($contract, array_map('intval', $validated['asset_ids']));

        return back()->with('success', __('contract.assets.added', ['count' => $added]));
    }

    public function destroy(Contract $contract, int $asset, RemoveContractAsset $removeAsset): RedirectResponse
    {
        Gate::authorize('update', $contract);

        $removeAsset->handle($contract, $asset);

        return back()->with('success', __('contract.assets.removed'));
    }
}
