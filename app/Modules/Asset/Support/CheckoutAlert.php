<?php

namespace App\Modules\Asset\Support;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;

/**
 * The placeholders of the issue/loan alerts (lang/th/alerts.php "events.checkout_*").
 */
class CheckoutAlert
{
    /**
     * @return array<string, string|null>
     */
    public static function replace(AssetCheckout $checkout, Asset $asset, User $actor): array
    {
        return [
            'no' => $checkout->checkout_no,
            'type' => __("ui.checkouts.types.{$checkout->type}"),
            'asset' => trim("{$asset->asset_code} {$asset->name}"),
            'quantity' => trim("{$checkout->quantity} ".($asset->unit ?? '')),
            'borrower' => $checkout->borrower_name,
            'actor' => $actor->name,
            'note' => $checkout->decision_note,
        ];
    }
}
