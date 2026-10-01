<?php

namespace App\Modules\Service\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\TicketParts;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketEvent;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * What the job sheet of a ticket shows, for the print page and the PDF alike. It is a document for
 * the customer, so internal notes never appear on it.
 */
class TicketSheet
{
    public function __construct(
        private TenantContext $context,
        private Modules $modules,
        private ListCustomers $listCustomers,
        private AssetDetails $assetDetails,
        private ContractLabels $contractLabels,
        private UserNames $userNames,
        private TicketParts $ticketParts,
    ) {}

    /**
     * @return array{company: string|null, ticket: array<string, mixed>, notes: list<array<string, mixed>>,
     *     parts: list<array<string, mixed>>|null}
     */
    public function handle(Ticket $ticket, User $user): array
    {
        $names = $this->userNames->handle([$ticket->assignee_id, $ticket->reported_by]);
        $customer = $ticket->customer_id && $this->modules->enabled('contract')
            ? collect($this->listCustomers->handle(withTrashed: true))->firstWhere('id', $ticket->customer_id)
            : null;
        $asset = $ticket->asset_id ? ($this->assetDetails->handle([$ticket->asset_id])[$ticket->asset_id] ?? null) : null;
        $contract = $ticket->contract_id ? ($this->contractLabels->handle([$ticket->contract_id])[$ticket->contract_id] ?? null) : null;

        return [
            'company' => $this->context->tenant()?->name,
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
                ])->all(),
            // Null = leave the parts table out (no stock module, or the user does not see stock).
            'parts' => $this->modules->enabled('inventory') && $user->can('part.view') ? $this->ticketParts->handle($ticket->id) : null,
        ];
    }
}
