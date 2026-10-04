<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\SharedAssetSearch;
use App\Modules\Inventory\Actions\SharedPartSearch;
use App\Modules\Platform\CrossTenant\ShareGateway;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parts and assets of other companies that share them with the user's company (read-only).
 * Needs the same view permission in the user's own company, and an active share (ShareGateway).
 */
class SharedSearchController extends Controller
{
    private const KINDS = ['parts' => 'parts.view', 'assets' => 'assets.view'];

    public function __invoke(Request $request, ShareGateway $gateway, SharedPartSearch $parts, SharedAssetSearch $assets): Response
    {
        $user = $request->user();
        $kinds = array_keys(array_filter(self::KINDS, fn (string $ability) => $user->can($ability)));
        abort_if($kinds === [] || $user->customer_id !== null, 403);

        $kind = in_array($request->input('kind'), $kinds, true) ? $request->input('kind') : $kinds[0];
        $ability = self::KINDS[$kind];
        $companies = $gateway->targets($user, $ability);
        $search = $request->string('search')->trim()->value();
        $chosen = $request->integer('company') ? $companies->firstWhere('id', $request->integer('company')) : null;

        // Each company is read inside itself; a search with no company goes through all of them.
        $results = ($search === '' && ! $chosen) ? [] : ($chosen ? collect([$chosen]) : $companies)
            ->map(fn (Tenant $company) => [
                'company' => $company->name,
                'rows' => $gateway->run($user, $company, $ability, fn (TenantShare $share) => $kind === 'parts'
                    ? $parts->handle($search)
                    : $assets->handle($search, array_map('intval', $share->branch_ids))),
            ])
            ->filter(fn (array $group) => $group['rows'] !== [])
            ->values()
            ->all();

        return Inertia::render('Platform/SharedSearch', [
            'filters' => ['kind' => $kind, 'company' => $chosen?->id, 'search' => $search],
            'kinds' => $kinds,
            'companies' => $companies->map(fn (Tenant $company) => $company->only(['id', 'name']))->values(),
            'results' => $results,
        ]);
    }
}
