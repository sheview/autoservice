<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Where a customer company stands against the period it has paid for
 * (tenants.subscription_starts_on / subscription_ends_on, both days included):
 *
 *   unlimited     no end date
 *   not_started   before the start date: the company can look but not change anything
 *   active        in the period
 *   expiring      in the period, WARN_DAYS or fewer days left: users are warned (popup)
 *   grace         ended up to GRACE_DAYS days ago: look only, nothing can be changed
 *   locked        ended longer ago: the company's users cannot use the system at all
 *
 * The platform tenant never has a subscription. Enforced by EnforceSubscription.
 */
class Subscription
{
    public const WARN_DAYS = 30;

    public const GRACE_DAYS = 30;

    public const UNLIMITED = 'unlimited';

    public const NOT_STARTED = 'not_started';

    public const ACTIVE = 'active';

    public const EXPIRING = 'expiring';

    public const GRACE = 'grace';

    public const LOCKED = 'locked';

    public const STATES = [self::UNLIMITED, self::NOT_STARTED, self::ACTIVE, self::EXPIRING, self::GRACE, self::LOCKED];

    /**
     * @return array{state: string, starts_on: string|null, ends_on: string|null, days_left: int|null,
     *     read_only_until: string|null, read_only: bool, locked: bool}
     */
    public static function of(Tenant $tenant, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse($today ?? today())->startOfDay();
        $starts = $tenant->subscription_starts_on ? CarbonImmutable::parse($tenant->subscription_starts_on)->startOfDay() : null;
        $ends = $tenant->subscription_ends_on ? CarbonImmutable::parse($tenant->subscription_ends_on)->startOfDay() : null;
        $graceEnds = $ends?->addDays(self::GRACE_DAYS);
        $daysLeft = $ends === null ? null : (int) $today->diffInDays($ends, false);

        $state = match (true) {
            $tenant->is_platform => self::UNLIMITED,
            $starts !== null && $today->lt($starts) => self::NOT_STARTED,
            $ends === null => self::UNLIMITED,
            $today->lte($ends) => $daysLeft <= self::WARN_DAYS ? self::EXPIRING : self::ACTIVE,
            $today->lte($graceEnds) => self::GRACE,
            default => self::LOCKED,
        };

        return [
            'state' => $state,
            'starts_on' => $starts?->toDateString(),
            'ends_on' => $ends?->toDateString(),
            'days_left' => $daysLeft,
            'read_only_until' => in_array($state, [self::GRACE, self::LOCKED], true) ? $graceEnds->toDateString() : null,
            'read_only' => in_array($state, [self::NOT_STARTED, self::GRACE], true),
            'locked' => $state === self::LOCKED,
        ];
    }
}
