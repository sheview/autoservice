<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\IpUsage;
use App\Modules\Asset\Support\IpRange;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Platform\Support\Modules;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Free IP" check: type a range, see which addresses are taken by registered devices and which
 * can be given out. Read-only: the page with ip-check.view, checking a range with ip-check.run.
 */
class IpCheckController extends Controller
{
    public function __invoke(Request $request, IpUsage $ipUsage, ListCustomers $listCustomers, Modules $modules): Response
    {
        $user = $request->user();
        abort_unless($user->can('ip-check.view'), 403);
        abort_if($user->customer_id !== null, 403);
        // Checking a range needs ip-check.run; without it the page only shows the form, disabled.
        $canRun = $user->can('ip-check.run');

        $range = $canRun ? $request->string('range')->trim()->value() : '';
        // "" = every device, "own" = the company's own devices, or a customer id
        $customer = (string) $request->input('customer', '');
        $customerId = $customer === 'own' ? 0 : ((int) $customer ?: null);

        $addresses = $range === '' ? [] : IpRange::parse($range);
        $customers = $modules->enabled('contract') ? $listCustomers->handle() : [];
        $names = collect($listCustomers->handle(withTrashed: true))->pluck('name', 'id');

        $rows = is_array($addresses) ? $ipUsage->handle($user, $addresses, $customerId) : [];
        $rows = array_map(fn (array $row) => [
            ...$row,
            'assets' => array_map(fn (array $asset) => [...$asset, 'customer' => $names[$asset['customer_id']] ?? null], $row['assets']),
        ], $rows);

        return Inertia::render('Asset/IpCheck', [
            'filters' => ['range' => $range, 'customer' => $customer],
            'error' => is_string($addresses) ? $addresses : null,
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'used' => count(array_filter($rows, fn (array $row) => $row['assets'] !== [])),
                'duplicates' => count(array_filter($rows, fn (array $row) => count($row['assets']) > 1)),
            ],
            'customers' => $customers,
            'max' => IpRange::MAX,
            'can' => ['run' => $canRun],
        ]);
    }
}
