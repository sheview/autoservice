<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Asset\Events\PurchasedItemHandedOut;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\RecordPurchaseIssued;

/**
 * What a purchase brought was handed out (Asset module): count it on the purchase request.
 */
class CountPurchaseIssued
{
    public function __construct(private RecordPurchaseIssued $record) {}

    public function handle(PurchasedItemHandedOut $event): void
    {
        $this->record->handle($event->purchaseRequestId, $event->qty, $event->actorId ? User::find($event->actorId) : null);
    }
}
