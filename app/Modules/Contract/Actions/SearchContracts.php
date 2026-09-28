<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Support\ContractPhase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The contract list query: search + filters (including the phase, see ContractPhase) + sort.
 */
class SearchContracts
{
    public const SORTABLE = ['contract_no', 'title', 'starts_on', 'ends_on', 'value', 'created_at'];

    /**
     * @return array{search: string, customer_id: int|null, phase: string|null, service_window: string|null,
     *     sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'customer_id' => $request->integer('customer_id') ?: null,
            'phase' => in_array($request->input('phase'), ContractPhase::PHASES, true) ? $request->input('phase') : null,
            'service_window' => in_array($request->input('service_window'), Contract::SERVICE_WINDOWS, true) ? $request->input('service_window') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'ends_on',
            'direction' => $request->input('direction') === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<Contract>
     */
    public function handle(array $filters): Builder
    {
        $today = now()->toDateString();
        $search = $filters['search'] ?? '';
        $active = fn (Builder $q) => $q->where('status', Contract::STATUS_ACTIVE);

        return Contract::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('contract_no', 'ilike', "%{$search}%")
                ->orWhere('title', 'ilike', "%{$search}%")
                ->orWhereHas('customer', fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"))))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when($filters['service_window'] ?? null, fn (Builder $q, $window) => $q->where('service_window', $window))
            ->when($filters['phase'] ?? null, fn (Builder $q, $phase) => match ($phase) {
                'draft', 'cancelled' => $q->where('status', $phase),
                'upcoming' => $active($q)->where('starts_on', '>', $today),
                'expired' => $active($q)->where('ends_on', '<', $today),
                'expiring' => $active($q)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)
                    ->whereRaw('ends_on - notify_days_before <= ?::date', [$today]),
                'active' => $active($q)->where('starts_on', '<=', $today)
                    ->whereRaw('ends_on - notify_days_before > ?::date', [$today]),
            })
            ->orderBy($filters['sort'] ?? 'ends_on', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }
}
