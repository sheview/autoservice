<?php

namespace App\Modules\Asset\Listeners;

use App\Modules\Asset\Actions\RestockBackorders;
use App\Modules\Inventory\Events\PartRestocked;

/**
 * Backordered request lines of a part are ready to hand out once it is in stock again.
 */
class ReadyBackordersOnRestock
{
    public function __construct(private RestockBackorders $restock) {}

    public function handle(PartRestocked $event): void
    {
        if ($event->qtyOnHand > 0) {
            $this->restock->handle($event->partId);
        }
    }
}
