<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes an issued or lent asset back: the form is closed and the asset is spare again.
 */
class ReturnCheckout
{
    public function handle(AssetCheckout $checkout, User $actor, ?string $note = null): AssetCheckout
    {
        if ($checkout->status !== AssetCheckout::STATUS_APPROVED) {
            throw ValidationException::withMessages(['checkout' => __('asset.checkouts.not_out')]);
        }

        return DB::transaction(function () use ($checkout, $actor, $note) {
            $checkout->update([
                'status' => AssetCheckout::STATUS_RETURNED,
                'returned_by' => $actor->id,
                'returned_by_name' => $actor->name,
                'returned_at' => now(),
                'return_note' => filled($note) ? $note : null,
            ]);
            $checkout->asset->update(['status' => Asset::STATUS_SPARE]);

            activity()->performedOn($checkout->asset)->causedBy($actor)->event('checkout_returned')
                ->withProperties(['checkout_no' => $checkout->checkout_no, 'note' => $note])
                ->log('รับคืน');

            return $checkout;
        });
    }
}
