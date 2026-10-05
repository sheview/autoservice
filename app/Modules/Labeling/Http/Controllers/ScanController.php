<?php

namespace App\Modules\Labeling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Labels printed before the QR page existed open /a/{ulid} (signing in first: the company comes
 * from the user). They keep working: the asset, if the user may see it, opens on its QR page.
 */
class ScanController extends Controller
{
    public function show(Request $request, string $asset, AssetDetails $assetDetails, AssetSummaries $assetSummaries, TenantContext $context): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('assets.view'), 403);

        $found = array_values($assetDetails->handle([$asset], byUlid: true))[0] ?? null;
        $row = $found ? ($assetSummaries->handle($user, ['ids' => [$found['id']]])[0] ?? null) : null;
        abort_if($row === null, 404);

        return redirect()->to(PublicUrl::forTenant($context->tenant(), '/q/'.rawurlencode($row['asset_code']).'?k='.$row['public_key']));
    }
}
