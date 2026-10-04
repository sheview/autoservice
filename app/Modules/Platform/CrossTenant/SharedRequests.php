<?php

namespace App\Modules\Platform\CrossTenant;

use App\Modules\Asset\Actions\CheckoutRequestStatuses;
use App\Modules\Asset\Actions\OpenOutsideCheckoutRequest;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\CrossTenantLink;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * Asking another company for parts and assets through a share, and following what became of it.
 *
 * The request is made in the owner company (B) as an issue/loan request of an outside person,
 * which B's approver decides on and B's store hands out as usual. A link remembers the ticket of
 * the asking company (A) it is for: A reads where the request stands through the link (it asked;
 * no share needed any more), and B sees who asked and for which job.
 */
class SharedRequests
{
    public function __construct(
        private ShareGateway $gateway,
        private TenantContext $context,
        private OpenOutsideCheckoutRequest $openRequest,
        private CheckoutRequestStatuses $statuses,
    ) {}

    /**
     * Makes the request in $target for the user's ticket (of the company they work in).
     *
     * @param  array{id: int, ticket_no: string}|null  $ticket
     * @param  array{purpose?: string|null, needed_by?: string|null, borrower_phone?: string|null, items: list<array<string, mixed>>}  $data
     */
    public function request(User $user, Tenant $target, ?array $ticket, array $data): CrossTenantLink
    {
        $from = $this->context->tenant();
        $abilities = collect($data['items'])
            ->map(fn (array $item) => $item['item_type'] === CheckoutItem::TYPE_PART ? 'parts.request' : 'assets.request')
            ->unique();
        foreach ($abilities as $ability) {
            abort_unless($this->gateway->targets($user, $ability)->contains('id', $target->id), 403);
        }

        $requester = "{$user->name} ({$from->name})";
        $request = $this->gateway->run($user, $target, $abilities->first(), fn (TenantShare $share) => $this->openRequest->handle(
            $requester,
            [...$data, 'purpose' => trim(($ticket ? __('platform.shares.for_ticket', ['no' => $ticket['ticket_no'], 'company' => $from->name]).' ' : '').($data['purpose'] ?? ''))],
            array_map('intval', $share->branch_ids),
        ));

        return CrossTenantLink::create([
            'source_tenant_id' => $from->id,
            'source_type' => CrossTenantLink::SOURCE_TICKET,
            'source_id' => $ticket['id'] ?? null,
            'source_label' => $ticket['ticket_no'] ?? null,
            'target_tenant_id' => $target->id,
            'target_type' => CrossTenantLink::TARGET_CHECKOUT,
            'target_id' => $request->id,
            'target_label' => $request->request_no,
            'created_by_id' => $user->id,
            'created_by_name' => $user->name,
        ]);
    }

    /**
     * Requests made in other companies for a ticket of the company being worked in, as they stand.
     *
     * @return list<array<string, mixed>>
     */
    public function forTicket(int $ticketId): array
    {
        return $this->withStatus(CrossTenantLink::query()
            ->where('source_tenant_id', $this->context->id())
            ->where('source_type', CrossTenantLink::SOURCE_TICKET)
            ->where('source_id', $ticketId)
            ->get());
    }

    /**
     * The latest requests the user made in other companies from the company being worked in.
     *
     * @return list<array<string, mixed>>
     */
    public function madeBy(User $user, int $limit = 20): array
    {
        return $this->withStatus(CrossTenantLink::query()
            ->where('source_tenant_id', $this->context->id())
            ->where('created_by_id', $user->id)
            ->where('target_type', CrossTenantLink::TARGET_CHECKOUT)
            ->latest('id')
            ->limit($limit)
            ->get());
    }

    /**
     * For a request of the company being worked in: which company asked, by whom, for which job.
     *
     * @return array{company: string|null, ticket_no: string|null, by: string|null}|null
     */
    public function askedBy(int $requestId): ?array
    {
        $link = CrossTenantLink::query()
            ->with('sourceTenant')
            ->where('target_tenant_id', $this->context->id())
            ->where('target_type', CrossTenantLink::TARGET_CHECKOUT)
            ->where('target_id', $requestId)
            ->first();

        return $link ? ['company' => $link->sourceTenant?->name, 'ticket_no' => $link->source_label, 'by' => $link->created_by_name] : null;
    }

    /**
     * The links with their requests as they stand, read inside each owner company.
     *
     * @param  Collection<int, CrossTenantLink>  $links
     * @return list<array<string, mixed>>
     */
    private function withStatus(Collection $links): array
    {
        $links->load('targetTenant');
        $byCompany = $links->groupBy('target_tenant_id')->map(fn (Collection $group) => $this->context->run(
            $group->first()->targetTenant,
            fn () => $this->statuses->handle($group->pluck('target_id')->map(fn ($id) => (int) $id)->all()),
        ));

        return $links->map(fn (CrossTenantLink $link) => [
            'id' => $link->id,
            'company' => $link->targetTenant?->name,
            'ticket_no' => $link->source_label,
            'request_no' => $link->target_label,
            'by' => $link->created_by_name,
            'at' => $link->created_at->toIso8601String(),
            ...($byCompany[$link->target_tenant_id][$link->target_id] ?? ['status' => null, 'items' => []]),
        ])->values()->all();
    }
}
