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
 * Approves (the parts go out of stock: an issue or a loan movement) or rejects (with a reason)
 * a part issue/loan form that waits.
 */
class DecidePartCheckout
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private SendAlert $sendAlert,
    ) {}

    public function handle(PartCheckout $checkout, bool $approve, User $actor, ?string $note = null): PartCheckout
    {
        if ($checkout->status !== PartCheckout::STATUS_PENDING) {
            throw ValidationException::withMessages(['checkout' => __('inventory.part_checkouts.not_pending')]);
        }
        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['note' => __('inventory.part_checkouts.reason_required')]);
        }

        return DB::transaction(function () use ($checkout, $approve, $actor, $note) {
            $part = $checkout->part;

            if ($approve) {
                // Refuses when the stock is no longer there (counted down since the request).
                $this->recordMovement->handle($part, $checkout->type === PartCheckout::TYPE_LOAN ? StockMovement::TYPE_LOAN : StockMovement::TYPE_ISSUE,
                    $checkout->quantity, $actor, ['reference' => $checkout->checkout_no, 'note' => $checkout->borrower_name]);
            }

            $checkout->update([
                'status' => $approve ? PartCheckout::STATUS_APPROVED : PartCheckout::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_by_name' => $actor->name,
                'decided_at' => now(),
                'decision_note' => filled($note) ? $note : null,
            ]);

            activity()->performedOn($part)->causedBy($actor)->event($approve ? 'part_checkout_approved' : 'part_checkout_rejected')
                ->withProperties(['checkout_no' => $checkout->checkout_no, 'note' => $note])
                ->log($approve ? 'อนุมัติเบิก/ยืมอะไหล่' : 'ไม่อนุมัติเบิก/ยืมอะไหล่');

            $this->sendAlert->handle($approve ? 'checkout_approved' : 'checkout_rejected',
                PartCheckoutAlert::replace($checkout, $part, $actor), route('inventory.parts.show', $part));

            return $checkout;
        });
    }
}
