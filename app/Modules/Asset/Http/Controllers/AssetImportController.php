<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\ImportAssets;
use App\Modules\Asset\Actions\StartAssetImport;
use App\Modules\Asset\Http\Requests\AssetImportRequest;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AssetImportController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('import', Asset::class);

        $imports = AssetImport::query()->latest('id')->paginate(10);
        // Uploaders outside this tenant (an impersonating superadmin) are not readable here and show no name.
        $names = User::whereIn('id', $imports->pluck('user_id')->filter())->pluck('name', 'id');

        return Inertia::render('Asset/Imports/Index', [
            'imports' => $imports->through(fn (AssetImport $import) => [
                ...$import->only(['id', 'file_name', 'status', 'total_rows', 'created_rows', 'updated_rows', 'failed_rows', 'errors']),
                'user' => $names[$import->user_id] ?? null,
                'created_at' => $import->created_at->toIso8601String(),
            ]),
            'maxRows' => ImportAssets::MAX_ROWS,
        ]);
    }

    public function store(AssetImportRequest $request, StartAssetImport $startImport): RedirectResponse
    {
        $startImport->handle($request->user(), $request->file('file'));

        return redirect()->route('asset.imports.index')->with('success', __('asset.imports.queued'));
    }
}
