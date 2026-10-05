<?php

namespace App\Modules\Labeling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\PublicAssetLabel;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public side of an asset's QR page (no sign-in): the device's code and broad name, a way for
 * staff to sign in and come back. An unknown company, an unknown code and a wrong key all give the
 * same page, after the same lookup.
 */
class QrPublicController extends Controller
{
    public function __construct(private TenantContext $context, private Modules $modules, private PublicAssetLabel $label) {}

    public function page(Request $request, ?Tenant $tenant, string $code): Response
    {
        $usable = $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('asset', $tenant);
        $key = $request->string('k')->trim()->limit(32, '')->value();
        $asset = $this->context->run($usable ? $tenant : null, fn () => $this->label->handle($code, $key));

        return Inertia::render('Labeling/QrPublic', [
            'company' => $asset ? $tenant->name : null,
            'asset' => $asset ? ['asset_code' => $asset['asset_code'], 'name' => $asset['name']] : null,
            'signIn' => $request->path() !== '' ? url($request->path().'/staff').($key !== '' ? '?k='.urlencode($key) : '') : null,
        ]);
    }
}
