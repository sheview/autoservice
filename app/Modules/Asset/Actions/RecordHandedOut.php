<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * Records that a new asset is already out with someone (issued or lent before it was registered):
 * an issue/loan form approved by the person registering it, on the day it was handed out.
 * It comes back with ReturnCheckout like any other.
 */
class RecordHandedOut
{
    public function __construct(private GenerateCheckoutNumber $generateNumber) {}

    /**
     * @param  array{type: string, borrower_name: string, on: string}  $handedOut
     */
    public function handle(Asset $asset, array $handedOut, User $actor): AssetCheckout
    {
        $checkout = AssetCheckout::create([
            'asset_id' => $asset->id,
            'checkout_no' => $this->generateNumber->handle(),
            'type' => $handedOut['type'],
            'status' => AssetCheckout::STATUS_APPROVED,
            'borrower_name' => $handedOut['borrower_name'],
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'decided_by' => $actor->id,
            'decided_by_name' => $actor->name,
            'decided_at' => CarbonImmutable::parse($handedOut['on'])->startOfDay(),
        ]);

        activity()->performedOn($asset)->causedBy($actor)->event('checkout_approved')
            ->withProperties(['checkout_no' => $checkout->checkout_no, 'type' => $checkout->type, 'borrower' => $checkout->borrower_name])
            ->log('บันทึกการเบิก/ยืมตอนเพิ่มทรัพย์สิน');

        return $checkout;
    }
}
