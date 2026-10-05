<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\ReturnIssuedPart;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes back parts already handed out on a line (given back unused, or handed out by mistake),
 * with the reason and who took them: they go back into stock (Inventory module; for a part
 * followed by serial number, the pieces chosen of those that went out on this line). The papers
 * already issued keep what was handed out and show the take-back as a corrected issue.
 */
class ReturnCheckoutPart
{
    public function __construct(private ReturnIssuedPart $returnPart) {}

    /**
     * @param  list<int>  $unitIds
     */
    public function handle(CheckoutItem $item, User $actor, int $qty, string $reason, array $unitIds = []): CheckoutItem
    {
        if ($item->item_type !== CheckoutItem::TYPE_PART || $item->outstanding() === 0) {
            throw ValidationException::withMessages(['qty' => __('asset.requests.not_out')]);
        }
        if ($qty < 1 || $qty > $item->outstanding()) {
            throw ValidationException::withMessages(['qty' => __('asset.requests.qty_over_outstanding', ['outstanding' => $item->outstanding()])]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('asset.requests.reason_required')]);
        }

        return DB::transaction(function () use ($item, $actor, $qty, $reason, $unitIds) {
            $item = CheckoutItem::query()->lockForUpdate()->findOrFail($item->id);
            $request = $item->request;

            $this->returnPart->handle((int) $item->part_id, $qty, $unitIds, $actor, $reason, [
                'checkout_item_id' => $item->id,
                'ticket_id' => $request->ticket_id,
                'reference' => $request->request_no,
            ]);

            $item->qty_returned += $qty;
            if ($item->outstanding() === 0) {
                $item->returned_at = now();
                $item->returned_by_name = $actor->name;
            }
            $item->return_condition = trim($reason);
            $item->save();

            activity()->performedOn($request)->causedBy($actor)->event('checkout_part_returned')
                ->withProperties(['request_no' => $request->request_no, 'item' => $item->item_name, 'qty' => $qty, 'reason' => $reason])
                ->log("รับคืนอะไหล่ {$item->item_name} × {$qty}: {$reason}");

            return $item;
        });
    }
}
