<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Notifications\LowStockNotification;
use Illuminate\Support\Facades\Notification;

/**
 * For the current tenant: one e-mail listing the active parts that are at or below their
 * reorder point and have not been e-mailed yet, to the staff who restock (stock-movements.create).
 * Each shortage is e-mailed once; restocking or a new reorder point re-arms it
 * (RecordStockMovement, SavePart).
 */
class NotifyLowStock
{
    public const RECIPIENT_PERMISSION = 'stock-movements.create';

    public function __construct(private UsersWithPermission $usersWithPermission) {}

    /**
     * @return int number of parts notified
     */
    public function handle(): int
    {
        $parts = Part::query()
            ->where('is_active', true)
            ->where('min_qty', '>', 0)
            ->whereColumn('qty_on_hand', '<=', 'min_qty')
            ->whereNull('low_stock_notified_at')
            ->orderBy('code')
            ->get();

        $recipients = $this->usersWithPermission->handle(self::RECIPIENT_PERMISSION);

        // Nobody to tell: leave the parts unmarked, so they are e-mailed once somebody can restock.
        if ($parts->isEmpty() || $recipients->isEmpty()) {
            return 0;
        }

        Notification::sendNow($recipients, new LowStockNotification($parts));

        Part::whereKey($parts->modelKeys())->update(['low_stock_notified_at' => now()]);

        return $parts->count();
    }
}
