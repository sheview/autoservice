<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\PartCheckout;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\PartCheckoutAlert;
use App\Modules\Platform\Actions\SendAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes lent parts back into stock (a return movement). Issued parts are used up and do not come back.
 */
class ReturnPartCheckout
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private SendAlert $sendAlert,
    ) {}

    public function handle(PartCheckout $checkout, User $actor, ?string $note = null): PartCheckout
    {
        if ($checkout->status !== PartCheckout::STATUS_APPROVED || $checkout->type !== PartCheckout::TYPE_LOAN) {
            throw ValidationException::withMessages(['checkout' => __('inventory.part_checkouts.not_out')]);
        }

        return DB::transaction(function () use ($checkout, $actor, $note) {
            $part = $checkout->part;
            $this->recordMovement->handle($part, StockMovement::TYPE_RETURN, $checkout->quantity, $actor,
                ['reference' => $checkout->checkout_no, 'note' => $note]);

            $checkout->update([
                'status' => PartCheckout::STATUS_RETURNED,
                'returned_by' => $actor->id,
                'returned_by_name' => $actor->name,
                'returned_at' => now(),
                'return_note' => filled($note) ? $note : null,
            ]);

            activity()->performedOn($part)->causedBy($actor)->event('part_checkout_returned')
                ->withProperties(['checkout_no' => $checkout->checkout_no, 'note' => $note])
                ->log('รับคืนอะไหล่');

            $this->sendAlert->handle('checkout_returned', PartCheckoutAlert::replace($checkout, $part, $actor), route('inventory.parts.show', $part));

            return $checkout;
        });
    }
}
