<?php

namespace App\Modules\Asset\Support;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Platform\Actions\SendAlert;

/**
 * Alerts about issue/loan requests (lang/th/alerts.php "events.checkout_*"): the request, who
 * it is for, its lines in short, and a link to it.
 */
class RequestAlert
{
    public static function send(string $event, CheckoutRequest $request, ?string $actor = null, ?string $note = null, ?CheckoutItem $item = null): void
    {
        $items = $item ? collect([$item]) : $request->items()->get();

        app(SendAlert::class)->handle($event, [
            'no' => $request->request_no,
            'borrower' => $request->borrower_name,
            'items' => $items->map(fn (CheckoutItem $line) => trim("{$line->item_name} × ".($line->qty_approved ?? $line->qty_requested).' '.($line->unit ?? '')))->implode(', '),
            'needed_by' => $request->needed_by?->format('d/m/Y'),
            'actor' => $actor,
            'note' => $note,
        ], route('asset.requests.show', $request));
    }
}
