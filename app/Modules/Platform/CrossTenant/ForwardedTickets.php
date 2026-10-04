<?php

namespace App\Modules\Platform\CrossTenant;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\CrossTenantLink;
use App\Modules\Service\Actions\NoteOnTicket;
use App\Modules\Service\Actions\OpenForwardedTicket;
use App\Modules\Service\Actions\TicketStatuses;
use App\Modules\Service\Models\Ticket;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Sending a ticket on to another company through a share (tickets.forward), so the job is not
 * keyed in twice: the other company gets its own ticket (its number, its people), linked to ours.
 * We see where theirs stands on our ticket, and each move of theirs is noted on ours (internal).
 */
class ForwardedTickets
{
    public function __construct(
        private ShareGateway $gateway,
        private TenantContext $context,
        private OpenForwardedTicket $openTicket,
        private TicketStatuses $statuses,
        private NoteOnTicket $noteOnTicket,
    ) {}

    /**
     * Opens in $target a ticket for our $ticket (of the company being worked in).
     */
    public function forward(User $user, Tenant $target, Ticket $ticket, ?string $note = null): CrossTenantLink
    {
        $from = $this->context->tenant();
        $data = [
            ...$ticket->only([
                'title', 'priority', 'contact_name', 'contact_phone', 'device_name', 'device_brand', 'device_model',
                'device_serial', 'device_serial_unknown', 'device_location', 'device_ip',
            ]),
            'description' => trim(implode("\n\n", array_filter([$note, $ticket->description]))),
        ];

        $theirs = $this->gateway->run($user, $target, 'tickets.forward', fn () => $this->openTicket->handle(
            "{$ticket->ticket_no} · {$from->name} · {$user->name}",
            $data,
        ));

        $link = CrossTenantLink::create([
            'source_tenant_id' => $from->id,
            'source_type' => CrossTenantLink::SOURCE_TICKET,
            'source_id' => $ticket->id,
            'source_label' => $ticket->ticket_no,
            'target_tenant_id' => $target->id,
            'target_type' => CrossTenantLink::TARGET_TICKET,
            'target_id' => $theirs->id,
            'target_label' => $theirs->ticket_no,
            'created_by_id' => $user->id,
            'created_by_name' => $user->name,
        ]);
        $this->noteOnTicket->handle($ticket->id, __('platform.shares.forwarded_to', ['company' => $target->name, 'no' => $theirs->ticket_no]));

        return $link;
    }

    /**
     * The tickets our ticket was forwarded as, as they stand at the other companies.
     *
     * @return list<array{id: int, company: string|null, ticket_no: string|null, status: string|null, assignee: string|null, resolved_at: string|null, by: string|null, at: string}>
     */
    public function forTicket(int $ticketId): array
    {
        $links = CrossTenantLink::query()
            ->with('targetTenant')
            ->where('source_tenant_id', $this->context->id())
            ->where('source_type', CrossTenantLink::SOURCE_TICKET)
            ->where('source_id', $ticketId)
            ->where('target_type', CrossTenantLink::TARGET_TICKET)
            ->get();

        return $links->map(function (CrossTenantLink $link) {
            $state = $this->context->run($link->targetTenant, fn () => $this->statuses->handle([(int) $link->target_id])[(int) $link->target_id] ?? null);

            return [
                'id' => $link->id,
                'company' => $link->targetTenant?->name,
                'ticket_no' => $link->target_label,
                'status' => $state['status'] ?? null,
                'assignee' => $state['assignee'] ?? null,
                'resolved_at' => $state['resolved_at'] ?? null,
                'by' => $link->created_by_name,
                'at' => $link->created_at->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * For a ticket of the company being worked in: which company sent it, as which ticket of theirs.
     *
     * @return array{company: string|null, ticket_no: string|null, by: string|null}|null
     */
    public function forwardedFrom(int $ticketId): ?array
    {
        $link = CrossTenantLink::query()
            ->with('sourceTenant')
            ->where('target_tenant_id', $this->context->id())
            ->where('target_type', CrossTenantLink::TARGET_TICKET)
            ->where('target_id', $ticketId)
            ->first();

        return $link ? ['company' => $link->sourceTenant?->name, 'ticket_no' => $link->source_label, 'by' => $link->created_by_name] : null;
    }

    /**
     * Our ticket moved: note it on the ticket of the company that sent it to us.
     */
    public function followUp(Ticket $ticket, ?string $actorName): void
    {
        $here = $this->context->tenant();
        $links = CrossTenantLink::query()
            ->with('sourceTenant')
            ->where('target_tenant_id', $here?->id)
            ->where('target_type', CrossTenantLink::TARGET_TICKET)
            ->where('target_id', $ticket->id)
            ->get();

        foreach ($links as $link) {
            $body = __('platform.shares.forward_moved', [
                'company' => $here->name,
                'no' => $ticket->ticket_no,
                'status' => __("ui.tickets.statuses.{$ticket->status}"),
                'by' => $actorName ?? '-',
            ]);
            $this->context->run($link->sourceTenant, fn () => $this->noteOnTicket->handle((int) $link->source_id, $body));
        }
    }
}
