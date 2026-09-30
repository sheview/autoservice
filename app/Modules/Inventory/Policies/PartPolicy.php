<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

/**
 * part.* permissions. Stock changes use the stock.* permissions (see StockMovementRequest).
 */
class PartPolicy extends TenantPolicy
{
    protected string $module = 'part';
}
