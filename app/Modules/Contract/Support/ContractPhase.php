<?php

namespace App\Modules\Contract\Support;

use App\Modules\Contract\Models\Contract;
use Carbon\CarbonInterface;

/**
 * Where a contract is in its life, from its status and dates (not stored: it changes every day).
 *
 *   draft | cancelled          from status
 *   upcoming                   active, not started yet
 *   active                     active and running
 *   expiring                   active, ends within notify_days_before
 *   expired                    active, ended
 */
class ContractPhase
{
    public const PHASES = ['draft', 'upcoming', 'active', 'expiring', 'expired', 'cancelled'];

    public static function of(Contract $contract, ?CarbonInterface $today = null): string
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return match (true) {
            $contract->status !== Contract::STATUS_ACTIVE => $contract->status,
            $contract->starts_on->gt($today) => 'upcoming',
            $contract->ends_on->lt($today) => 'expired',
            $contract->ends_on->copy()->subDays($contract->notify_days_before)->lte($today) => 'expiring',
            default => 'active',
        };
    }
}
