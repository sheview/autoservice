<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCheckout;
use App\Modules\Inventory\Support\PartCheckoutAlert;
use App\Modules\Platform\Actions\SendAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asks to issue or lend some of a part. The stock goes out when an approver approves
 * (DecidePartCheckout); until then the form holds its quantity, so no more can be asked for than
 * is on hand and not already asked for. The project starts from the part's own contract.
 */
class RequestPartCheckout
{
    public function __construct(
        private GeneratePartCheckoutNumber $generateNumber,
        private UserNames $userNames,
        private SendAlert $sendAlert,
    ) {}

    /**
     * @param  array{type: string, quantity?: int|null, contract_id?: int|null, borrower_user_id?: int|null, borrower_name?: string|null,
     *     borrower_department?: string|null, borrower_phone?: string|null, purpose?: string|null, due_on?: string|null}  $data  validated
     */
    public function handle(Part $part, array $data, User $actor): PartCheckout
    {
        return DB::transaction(function () use ($part, $data, $actor) {
            // One request at a time per part, even when two people press at once.
            $part = Part::query()->lockForUpdate()->findOrFail($part->id);

            $quantity = max(1, (int) ($data['quantity'] ?? 1));
            $left = self::availableQuantity($part);
            if (! $part->is_active || $left < 1) {
                throw ValidationException::withMessages(['type' => __('inventory.part_checkouts.not_available')]);
            }
            if ($quantity > $left) {
                throw ValidationException::withMessages(['quantity' => __('inventory.part_checkouts.not_enough', ['available' => $left, 'unit' => $part->unit])]);
            }

            $userId = $data['borrower_user_id'] ?? null;

            $checkout = PartCheckout::create([
                'part_id' => $part->id,
                'contract_id' => array_key_exists('contract_id', $data) ? $data['contract_id'] : $part->contract_id,
                'checkout_no' => $this->generateNumber->handle(),
                'type' => $data['type'],
                'quantity' => $quantity,
                'status' => PartCheckout::STATUS_PENDING,
                'borrower_user_id' => $userId,
                'borrower_name' => $userId ? ($this->userNames->handle([$userId])[$userId] ?? $data['borrower_name']) : trim($data['borrower_name']),
                'borrower_department' => $data['borrower_department'] ?? null,
                'borrower_phone' => $data['borrower_phone'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'due_on' => $data['type'] === PartCheckout::TYPE_LOAN ? $data['due_on'] : null,
                'requested_by' => $actor->id,
                'requested_by_name' => $actor->name,
            ]);

            activity()->performedOn($part)->causedBy($actor)->event('part_checkout_requested')
                ->withProperties(['checkout_no' => $checkout->checkout_no, 'type' => $checkout->type, 'quantity' => $quantity, 'borrower' => $checkout->borrower_name])
                ->log('ขอเบิก/ยืมอะไหล่');

            $this->sendAlert->handle('checkout_requested', PartCheckoutAlert::replace($checkout, $part, $actor), route('inventory.parts.show', $part));

            return $checkout;
        });
    }

    /**
     * How many can still be asked for: on hand minus what pending forms hold (approved forms
     * have already taken theirs out of stock).
     */
    public static function availableQuantity(Part $part): int
    {
        $held = (int) PartCheckout::query()->where('part_id', $part->id)->where('status', PartCheckout::STATUS_PENDING)->sum('quantity');

        return max(0, (int) $part->qty_on_hand - $held);
    }
}
