<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCheckout;

/**
 * The placeholders of the issue/loan alerts (lang/th/alerts.php "events.checkout_*") for a part.
 */
class PartCheckoutAlert
{
    /**
     * @return array<string, string|null>
     */
    public static function replace(PartCheckout $checkout, Part $part, User $actor): array
    {
        return [
            'no' => $checkout->checkout_no,
            'type' => __("ui.part_checkouts.types.{$checkout->type}"),
            'asset' => trim("{$part->code} {$part->name}"),
            'quantity' => trim("{$checkout->quantity} {$part->unit}"),
            'borrower' => $checkout->borrower_name,
            'actor' => $actor->name,
            'note' => $checkout->decision_note,
        ];
    }
}
