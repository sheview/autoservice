<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Actions\SendAlert;
use App\Modules\Platform\Support\Modules;

/**
 * Alerts about purchase requests (lang/th/alerts.php "events.purchase_*"): who asked, what and how
 * many, for which project, by when, and a link to the request. Sent where the company set its
 * alerts (AlertSettings), never chosen by the requester.
 */
class PurchaseAlert
{
    public static function send(string $event, PurchaseRequest $request, ?string $actor = null, ?string $note = null, ?int $quantity = null): void
    {
        $project = $request->contract_id && app(Modules::class)->enabled('contract')
            ? (app(ContractLabels::class)->handle([$request->contract_id])[$request->contract_id] ?? null)
            : null;

        app(SendAlert::class)->handle($event, [
            'no' => $request->pr_no,
            'requester' => $request->requested_by_name,
            'item' => $request->item_name,
            'qty' => trim(($quantity ?? $request->quantity).' '.$request->unit),
            'project' => $project ? "{$project['contract_no']} {$project['title']}" : null,
            'needed_by' => $request->needed_by?->format('d/m/Y'),
            'actor' => $actor,
            'note' => $note,
        ], route('inventory.purchase-requests.show', $request));
    }
}
