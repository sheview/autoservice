<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Models\CustomerSite;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The sites of the customers the user may see, for the map: those placed on it (lat / lng set),
 * and how many are not placed yet.
 */
class SitePlaces
{
    /**
     * @param  array{customer_id?: int|null, province?: string|null}  $filters
     * @return array{places: list<array{id: int, name: string, address: string|null, province: string|null, lat: float, lng: float,
     *     customer_id: int, customer: string}>, unplaced: int, provinces: list<string>}
     */
    public function handle(User $user, array $filters = []): array
    {
        $sites = fn () => CustomerSite::query()
            ->whereIn('customer_id', ContractScope::customers(Customer::query(), $user)->select('id'))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id));

        $places = $sites()
            ->with('customer:id,name')
            ->when($filters['province'] ?? null, fn (Builder $q, $province) => $q->where('province', $province))
            ->whereNotNull('lat')->whereNotNull('lng')
            ->orderBy('name')
            ->get()
            ->map(fn (CustomerSite $site) => [
                ...$site->only(['id', 'name', 'address', 'province', 'customer_id']),
                'lat' => (float) $site->lat,
                'lng' => (float) $site->lng,
                'customer' => $site->customer?->name ?? '',
            ])
            ->all();

        return [
            'places' => $places,
            'unplaced' => $sites()->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))->count(),
            'provinces' => $sites()->whereNotNull('province')->where('province', '!=', '')
                ->distinct()->orderBy('province')->pluck('province')->all(),
        ];
    }
}
