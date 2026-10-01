<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Jobs\ImportAssetsJob;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores an uploaded asset Excel file and queues ImportAssetsJob for it.
 */
class StartAssetImport
{
    public function __construct(private TenantContext $context) {}

    public function handle(User $user, UploadedFile $file): AssetImport
    {
        $path = $file->storeAs(
            'asset-imports/'.$this->context->id(),
            Str::ulid().'.'.strtolower($file->getClientOriginalExtension()),
            'local',
        );

        $import = AssetImport::create([
            'user_id' => $user->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
        ]);

        ImportAssetsJob::dispatch($import->id, DataScope::of($user, 'assets.import') === PermissionCatalog::SCOPE_ALL, $user->branch_id);

        return $import;
    }
}
