<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The issue/loan list: forms of assets the user may see (SearchAssets), with search, filters and sort.
 */
class SearchCheckouts
{
    public const SORTABLE = ['created_at', 'checkout_no', 'due_on'];

    /** "open" = waiting or out; "overdue" = loans past their due date; or one status. */
    public const STATUS_FILTERS = ['open', 'overdue', 'all', ...AssetCheckout::STATUSES];

    /**
     * @return array{search: string, status: string, type: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), self::STATUS_FILTERS, true) ? $request->input('status') : 'open',
            'type' => in_array($request->input('type'), AssetCheckout::TYPES, true) ? $request->input('type') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<AssetCheckout>
     */
    public function handle(User $user, array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? 'open';

        return AssetCheckout::query()
            ->with('asset:id,ulid,asset_code,name,serial_number')
            ->whereHas('asset', fn (Builder $q) => SearchAssets::visibleTo($q, $user))
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('checkout_no', 'ilike', "%{$search}%")
                ->orWhere('borrower_name', 'ilike', "%{$search}%")
                ->orWhere('borrower_department', 'ilike', "%{$search}%")
                ->orWhereHas('asset', fn ($q) => $q
                    ->where('asset_code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%")
                    ->orWhere('serial_number', 'ilike', "%{$search}%"))))
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('status', AssetCheckout::OPEN_STATUSES))
            ->when($status === 'overdue', fn (Builder $q) => $q->where('status', AssetCheckout::STATUS_APPROVED)
                ->whereNotNull('due_on')->where('due_on', '<', today()->toDateString()))
            ->when(in_array($status, AssetCheckout::STATUSES, true), fn (Builder $q) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('type', $type))
            ->when(($filters['sort'] ?? 'created_at') === 'due_on',
                fn (Builder $q) => $q->orderByRaw('due_on '.($filters['direction'] === 'asc' ? 'asc' : 'desc').' nulls last'),
                fn (Builder $q) => $q->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc'))
            ->orderByDesc('id');
    }
}
