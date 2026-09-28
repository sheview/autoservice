<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Notifications\ContractsExpiringNotification;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Notification;

/**
 * For the current tenant: e-mails the users with contract.update about active contracts that
 * reached their notice window (ends_on - notify_days_before) and were not e-mailed yet.
 * Each contract is e-mailed once; changing its end date re-arms it (SaveContract).
 */
class NotifyExpiringContracts
{
    public const PERMISSION = 'contract.update';

    public function __construct(
        private UsersWithPermission $usersWithPermission,
        private TenantContext $context,
    ) {}

    /**
     * @return int number of contracts notified
     */
    public function handle(): int
    {
        $today = now()->toDateString();

        $contracts = Contract::query()
            ->with('customer:id,name')
            ->where('status', Contract::STATUS_ACTIVE)
            ->whereNull('expiry_notified_at')
            ->where('ends_on', '>=', $today)
            ->whereRaw('ends_on - notify_days_before <= ?::date', [$today])
            ->orderBy('ends_on')
            ->get();

        if ($contracts->isEmpty()) {
            return 0;
        }

        $recipients = $this->usersWithPermission->handle(self::PERMISSION);
        if ($recipients->isNotEmpty()) {
            Notification::sendNow($recipients, new ContractsExpiringNotification($contracts, $this->context->tenant()->name));
        }

        Contract::whereKey($contracts->modelKeys())->update(['expiry_notified_at' => now()]);

        return $contracts->count();
    }
}
