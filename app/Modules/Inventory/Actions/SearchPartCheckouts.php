<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartCheckout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The part issue/loan list: search, filters and sort. Parts have no branch or customer, so every
 * staff member who handles these forms sees all of them.
 */
class SearchPartCheckouts
{
    public const SORTABLE = ['created_at', 'checkout_no', 'due_on'];

    /** "open" = waiting, or lent and not back; "overdue" = loans past their due date; or one status. */
    public const STATUS_FILTERS = ['open', 'overdue', 'all', ...PartCheckout::STATUSES];

    /**
     * @return array{search: string, status: string, type: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), self::STATUS_FILTERS, true) ? $request->input('status') : 'open',
            'type' => in_array($request->input('type'), PartCheckout::TYPES, true) ? $request->input('type') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom(), plus borrower_user_id / borrower_name / contract_id (summaries)
     * @return Builder<PartCheckout>
     */
    public function handle(array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? 'open';

        return PartCheckout::query()
            ->with('part:id,code,name,part_number,unit')
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('checkout_no', 'ilike', "%{$search}%")
                ->orWhere('borrower_name', 'ilike', "%{$search}%")
                ->orWhere('borrower_department', 'ilike', "%{$search}%")
                ->orWhereHas('part', fn ($q) => $q
                    ->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%")
                    ->orWhere('part_number', 'ilike', "%{$search}%"))))
            ->when($status === 'open', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('status', PartCheckout::STATUS_PENDING)
                ->orWhere(fn ($q) => $q->where('status', PartCheckout::STATUS_APPROVED)->where('type', PartCheckout::TYPE_LOAN))))
            ->when($status === 'overdue', fn (Builder $q) => $q->where('status', PartCheckout::STATUS_APPROVED)->where('type', PartCheckout::TYPE_LOAN)
                ->whereNotNull('due_on')->where('due_on', '<', today()->toDateString()))
            ->when(in_array($status, PartCheckout::STATUSES, true), fn (Builder $q) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $q, $type) => $q->where('type', $type))
            ->when($filters['part_id'] ?? null, fn (Builder $q, $id) => $q->where('part_id', $id))
            ->when($filters['borrower_user_id'] ?? null, fn (Builder $q, $id) => $q->where('borrower_user_id', $id))
            ->when($filters['borrower_name'] ?? null, fn (Builder $q, $name) => $q->whereNull('borrower_user_id')->where('borrower_name', $name))
            ->when($filters['contract_id'] ?? null, fn (Builder $q, $id) => $q->where('contract_id', $id))
            ->when(($filters['sort'] ?? 'created_at') === 'due_on',
                fn (Builder $q) => $q->orderByRaw('due_on '.(($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc').' nulls last'),
                fn (Builder $q) => $q->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc'))
            ->orderByDesc('id');
    }
}
