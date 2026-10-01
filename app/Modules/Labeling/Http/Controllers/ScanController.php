<?php

namespace App\Modules\Labeling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Maintenance\Actions\PmHistoryForAsset;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketsForAsset;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a label's QR code opens (/a/{ulid}), made for a phone: what the asset is, its latest
 * jobs, and a button to report a problem. Signing in first is required: the tenant comes from
 * the user, and whoever cannot see the asset (other customer, branch or tenant) gets a 404.
 */
class ScanController extends Controller
{
    public function show(
        Request $request,
        string $asset,
        AssetDetails $assetDetails,
        AssetSummaries $assetSummaries,
        ListCustomers $listCustomers,
        TicketsForAsset $ticketsForAsset,
        PmHistoryForAsset $pmHistory,
        Modules $modules,
    ): Response {
        $user = $request->user();
        abort_unless($user->can('asset.view'), 403);

        $found = array_values($assetDetails->handle([$asset], byUlid: true))[0] ?? null;
        $row = $found ? ($assetSummaries->handle($user, ['ids' => [$found['id']]])[0] ?? null) : null;
        abort_if($row === null, 404);

        $serviceOn = $modules->enabled('service');
        $customer = $row['customer_id'] && $modules->enabled('contract')
            ? collect($listCustomers->handle(withTrashed: true))->firstWhere('id', $row['customer_id'])['name'] ?? null
            : null;

        return Inertia::render('Labeling/Scan', [
            'asset' => [...collect($row)->only(['ulid', 'asset_code', 'name', 'category', 'branch', 'status', 'serial_number', 'property_no'])->all(), 'customer' => $customer],
            'tickets' => $serviceOn && $user->can('ticket.view') ? array_slice($ticketsForAsset->handle($row['id']), 0, 3) : null,
            'lastPm' => $modules->enabled('maintenance') && $user->can('pm.view') ? ($pmHistory->handle($row['id'], 1)[0] ?? null) : null,
            'can' => [
                'openTicket' => $serviceOn && $user->can('ticket.create'),
            ],
        ]);
    }
}
