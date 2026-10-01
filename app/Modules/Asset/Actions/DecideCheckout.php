<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves or rejects a request (RequestCheckout). An approved one hands the asset over: it is
 * then in use until it comes back (ReturnCheckout). A rejection says why.
 */
class DecideCheckout
{
    public function handle(AssetCheckout $checkout, bool $approve, User $actor, ?string $note = null): AssetCheckout
    {
        if ($checkout->status !== AssetCheckout::STATUS_PENDING) {
            throw ValidationException::withMessages(['checkout' => __('asset.checkouts.not_pending')]);
        }
        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['note' => __('asset.checkouts.reason_required')]);
        }

        return DB::transaction(function () use ($checkout, $approve, $actor, $note) {
            $checkout->update([
                'status' => $approve ? AssetCheckout::STATUS_APPROVED : AssetCheckout::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_by_name' => $actor->name,
                'decided_at' => now(),
                'decision_note' => filled($note) ? $note : null,
            ]);

            $asset = $checkout->asset;
            if ($approve) {
                $asset->update(['status' => Asset::STATUS_IN_USE]);
            }

            activity()->performedOn($asset)->causedBy($actor)->event($approve ? 'checkout_approved' : 'checkout_rejected')
                ->withProperties(['checkout_no' => $checkout->checkout_no, 'note' => $note])
                ->log($approve ? 'อนุมัติเบิก/ยืม' : 'ไม่อนุมัติเบิก/ยืม');

            return $checkout;
        });
    }
}
