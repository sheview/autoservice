<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Inventory\Actions\TicketParts;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The job sheet of a ticket, laid out for A4 and printed with the browser (like the asset labels):
 * what was reported, what was done, the parts used and room for both signatures.
 * It is a document for the customer, so internal notes never appear on it.
 */
class TicketPrintController extends Controller
{
    public function show(
        Request $request,
        Ticket $ticket,
        TenantContext $context,
        Modules $modules,
        ListCustomers $listCustomers,
        AssetDetails $assetDetails,
        ContractLabels $contractLabels,
        UserNames $userNames,
        TicketParts $ticketParts,
    ): Response {
        Gate::authorize('view', $ticket);

        $user = $request->user();
        $names = $userNames->handle([$ticket->assignee_id, $ticket->reported_by]);
        $customer = $ticket->customer_id && $modules->enabled('contract')
            ? collect($listCustomers->handle(withTrashed: true))->firstWhere('id', $ticket->customer_id)
            : null;
        $asset = $ticket->asset_id ? ($assetDetails->handle([$ticket->asset_id])[$ticket->asset_id] ?? null) : null;
        $contract = $ticket->contract_id ? ($contractLabels->handle([$ticket->contract_id])[$ticket->contract_id] ?? null) : null;

        return Inertia::render('Service/Tickets/Print', [
            'company' => $context->tenant()?->name,
            'ticket' => [
                ...$ticket->only(['ulid', 'ticket_no', 'title', 'description', 'status', 'priority', 'source', 'contact_name', 'contact_phone']),
                'customer' => $customer['name'] ?? null,
                'asset' => $asset ? collect($asset)->only(['asset_code', 'name'])->all() : null,
                'contract_no' => $contract['contract_no'] ?? null,
                'branch' => $ticket->branch?->name,
                'assignee' => $names[$ticket->assignee_id] ?? null,
                'reporter' => $names[$ticket->reported_by] ?? null,
                ...collect(['created_at', 'responded_at', 'resolved_at', 'closed_at'])
                    ->mapWithKeys(fn ($field) => [$field => $ticket->{$field}?->toIso8601String()]),
            ],
            // What was said and done, without internal notes.
            'notes' => $ticket->events()
                ->where('is_internal', false)
                ->whereNotNull('body')
                ->whereIn('type', [TicketEvent::TYPE_COMMENT, TicketEvent::TYPE_STATUS])
                ->orderBy('id')->get()->map(fn (TicketEvent $event) => [
                    ...$event->only(['id', 'body', 'user_name']),
                    'at' => $event->created_at->toIso8601String(),
                ]),
            // Null = leave the parts table out (no stock module, or the user does not see stock).
            'parts' => $modules->enabled('inventory') && $user->can('part.view') ? $ticketParts->handle($ticket->id) : null,
        ]);
    }
}
