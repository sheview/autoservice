<?php

namespace App\Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stock of a part went up (received, returned, counted higher): other modules may now hand out
 * what waited for it (Asset module: backordered request lines).
 */
class PartRestocked
{
    use Dispatchable;

    public function __construct(public int $partId, public int $qtyOnHand) {}
}
