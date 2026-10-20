<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\ImportAssets;
use App\Modules\Asset\Actions\StartAssetImport;
use App\Modules\Asset\Http\Requests\AssetImportRequest;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Contract\Actions\EditableContracts;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AssetImportController extends Controller
{
    public function index(Request $request, Modules $modules, ListCustomers $listCustomers, EditableContracts $editableContracts): Response
    {
        Gate::authorize('import', Asset::class);

        $imports = AssetImport::query()->latest('id')->paginate(10);
        // Uploaders outside this tenant (an impersonating superadmin) are not readable here and show no name.
        $names = User::whereIn('id', $imports->pluck('user_id')->filter())->pluck('name', 'id');

        // The customer and MA contract the whole file may go to (the contract column still works per row).
        $customers = $modules->enabled('contract') ? $listCustomers->handle() : [];
        $contracts = $editableContracts->handle($request->user(), array_column($customers, 'id'));

        return Inertia::render('Asset/Imports/Index', [
            'imports' => $imports->through(fn (AssetImport $import) => [
                ...$import->only(['id', 'file_name', 'status', 'total_rows', 'created_rows', 'updated_rows', 'failed_rows', 'errors']),
                'user' => $names[$import->user_id] ?? null,
                'created_at' => $import->created_at->toIso8601String(),
            ]),
            'maxRows' => ImportAssets::MAX_ROWS,
            'customers' => $customers,
            // by customer id
            'contracts' => (object) collect($contracts)->map(fn (array $list) => array_map(
                fn (array $contract) => ['id' => $contract['id'], 'label' => "{$contract['contract_no']} · {$contract['title']}"],
                $list,
            ))->all(),
        ]);
    }

    public function store(AssetImportRequest $request, StartAssetImport $startImport): RedirectResponse
    {
        $startImport->handle($request->user(), $request->file('file'), array_filter(
            $request->safe()->only(['customer_id', 'contract_id']),
            fn ($value) => $value !== null,
        ));

        return redirect()->route('asset.imports.index')->with('success', __('asset.imports.queued'));
    }
}
