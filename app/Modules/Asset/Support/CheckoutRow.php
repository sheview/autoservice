<?php

namespace App\Modules\Asset\Support;

use App\Modules\Asset\Models\AssetCheckout;

/**
 * An issue/loan form as the pages show it (the list, the asset page).
 */
class CheckoutRow
{
    /**
     * @return array<string, mixed>
     */
    public static function of(AssetCheckout $checkout): array
    {
        return [
            ...$checkout->only([
                'ulid', 'checkout_no', 'type', 'status', 'borrower_name', 'borrower_department', 'borrower_phone', 'purpose',
                'requested_by', 'requested_by_name', 'decided_by_name', 'decision_note', 'returned_by_name', 'return_note',
            ]),
            'due_on' => $checkout->due_on?->toDateString(),
            'overdue' => $checkout->isOverdue(),
            'requested_at' => $checkout->created_at?->toIso8601String(),
            'decided_at' => $checkout->decided_at?->toIso8601String(),
            'returned_at' => $checkout->returned_at?->toIso8601String(),
            'asset' => $checkout->relationLoaded('asset') && $checkout->asset
                ? $checkout->asset->only(['ulid', 'asset_code', 'name', 'serial_number'])
                : null,
        ];
    }
}
