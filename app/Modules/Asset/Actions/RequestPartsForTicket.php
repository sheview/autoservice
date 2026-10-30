<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\ItemPurpose;

/**
 * Parts used on a job asked for from another module (the one-page close of the Service module):
 * an issue request in the technician's name for that ticket, sent for approval at once. Stock
 * moves only when it is handed out, like any request.
 */
class RequestPartsForTicket
{
    public function __construct(
        private SaveCheckoutRequest $save,
        private SubmitCheckoutRequest $submit,
    ) {}

    /**
     * @param  array{id: int, ticket_no: string, contract_id: int|null, customer_name: string|null}  $ticket
     * @param  list<array{part_id: int, qty: int}>  $lines
     */
    public function handle(array $ticket, array $lines, User $actor): CheckoutRequest
    {
        $forCustomer = filled($ticket['customer_name']);

        $request = $this->save->handle(null, [
            'borrower_user_id' => $actor->id,
            'ticket_id' => $ticket['id'],
            'contract_id' => $ticket['contract_id'],
            'items' => array_map(fn (array $line) => [
                'item_type' => CheckoutItem::TYPE_PART,
                'part_id' => (int) $line['part_id'],
                'qty' => (int) $line['qty'],
                'purpose' => ItemPurpose::OTHER,
                'purpose_other' => __('asset.requests.for_ticket', ['no' => $ticket['ticket_no']]),
                'for_internal' => ! $forCustomer,
                'customer_name' => $forCustomer ? $ticket['customer_name'] : null,
                'ticket_id' => $ticket['id'],
            ], $lines),
        ], $actor);

        return $this->submit->handle($request, $actor);
    }
}
