<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * Asset figures of the current tenant as they stand today, as plain arrays, for the Reporting
 * module (which must not use the Asset model directly).
 */
class AssetReport
{
    /** Warranties ending within this many days count as "expiring". */
    public const EXPIRING_DAYS = 90;

    public function __construct(private CategoryNames $categoryNames) {}

    /**
     * @return array{total: int, by_status: array<string, int>, by_category: list<array{name: string, count: int}>,
     *     warranty_expiring: int, warranty_expired: int, expiring_days: int}
     */
    public function handle(): array
    {
        $byStatus = Asset::query()->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
        $byCategory = Asset::query()->groupBy('category_id')->selectRaw('category_id, count(*) as total')->pluck('total', 'category_id');
        $names = $this->categoryNames->handle(withTrashed: true);

        // Warranty only matters for assets that are still around.
        $inService = fn () => Asset::query()->where('status', '!=', Asset::STATUS_RETIRED)->whereNotNull('warranty_expires_at');
        $today = today()->toDateString();

        return [
            'total' => (int) $byStatus->sum(),
            'by_status' => collect(Asset::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) ($byStatus[$status] ?? 0)])->all(),
            'by_category' => $byCategory->sortDesc()
                ->map(fn ($count, $categoryId) => ['name' => $names[$categoryId] ?? '-', 'count' => (int) $count])
                ->values()->all(),
            'warranty_expiring' => $inService()->whereBetween('warranty_expires_at', [$today, today()->addDays(self::EXPIRING_DAYS)->toDateString()])->count(),
            'warranty_expired' => $inService()->where('warranty_expires_at', '<', $today)->count(),
            'expiring_days' => self::EXPIRING_DAYS,
        ];
    }
}
