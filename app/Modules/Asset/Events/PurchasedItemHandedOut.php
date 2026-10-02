<?php

namespace App\Modules\Asset\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something bought on a purchase request (Inventory module) was handed out on an issue/loan
 * request line tied to it: the purchase request counts it as issued.
 */
class PurchasedItemHandedOut
{
    use Dispatchable;

    public function __construct(public int $purchaseRequestId, public int $qty, public ?int $actorId = null) {}
}
