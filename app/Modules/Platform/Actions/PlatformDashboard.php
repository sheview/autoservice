<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Subscription;

/**
 * The platform's home page: how many customer companies there are and where they stand, the ones
 * whose paid period ends soon or has ended (to renew), and the newest ones.
 * Reads the tenants table, which is platform data (no RLS).
 */
class PlatformDashboard
{
    /** Companies whose period ends within this many days are listed to renew. */
    public const WATCH_DAYS = 60;

    public const NEWEST = 8;

    /**
     * @return array{counts: array<string, int>, renewals: list<array<string, mixed>>, newest: list<array<string, mixed>>, watch_days: int}
     */
    public function handle(): array
    {
        $companies = Tenant::query()->where('is_platform', false)->orderBy('name')->get()
            ->map(fn (Tenant $tenant) => [
                ...$tenant->only(['ulid', 'name', 'subdomain', 'status']),
                'created_at' => $tenant->created_at?->toIso8601String(),
                'subscription' => Subscription::of($tenant),
            ]);

        $states = $companies->countBy(fn (array $company) => $company['subscription']['state']);

        return [
            'counts' => [
                'total' => $companies->count(),
                'active' => (int) (($states[Subscription::ACTIVE] ?? 0) + ($states[Subscription::UNLIMITED] ?? 0)),
                'expiring' => (int) ($states[Subscription::EXPIRING] ?? 0),
                'grace' => (int) ($states[Subscription::GRACE] ?? 0),
                'locked' => (int) ($states[Subscription::LOCKED] ?? 0),
                'not_started' => (int) ($states[Subscription::NOT_STARTED] ?? 0),
                'suspended' => $companies->where('status', Tenant::STATUS_SUSPENDED)->count(),
            ],
            // Ending within WATCH_DAYS, or already ended: soonest (or longest gone) first.
            'renewals' => $companies
                ->filter(fn (array $company) => $company['subscription']['days_left'] !== null && $company['subscription']['days_left'] <= self::WATCH_DAYS)
                ->sortBy(fn (array $company) => $company['subscription']['days_left'])
                ->values()->all(),
            'newest' => $companies->sortByDesc('created_at')->take(self::NEWEST)->values()->all(),
            'watch_days' => self::WATCH_DAYS,
        ];
    }
}
