<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Models\PartCheckout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The part issue/loan list: search, filters and sort, within what the user may see (scopeOf):
 * approvers and askers with scope all see every form; with scope own only their own.
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
     * @param  User|null  $user  the viewer: only the forms they may see (visibleTo); null = all
     * @return Builder<PartCheckout>
     */
    public function handle(array $filters, ?User $user = null): Builder
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? 'open';

        return self::visibleTo(PartCheckout::query(), $user)
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

    /**
     * How far the user reaches part issue/loan forms: the wider of asset-checkouts.approve
     * (approvers) and parts.issue (askers). Parts have no branch, so branch = all; customer
     * accounts reach none; own = forms the user asked for or that are made out to them.
     */
    public static function scopeOf(User $user): ?string
    {
        if ($user->customer_id !== null) {
            return null;
        }
        $scopes = array_filter([DataScope::of($user, 'asset-checkouts.approve'), DataScope::of($user, 'parts.issue')]);
        foreach ([PermissionCatalog::SCOPE_ALL, PermissionCatalog::SCOPE_BRANCH] as $wide) {
            if (in_array($wide, $scopes, true)) {
                return PermissionCatalog::SCOPE_ALL;
            }
        }

        return in_array(PermissionCatalog::SCOPE_OWN, $scopes, true) ? PermissionCatalog::SCOPE_OWN : null;
    }

    /**
     * Narrows a forms query to the ones the user may see (scopeOf).
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function visibleTo(Builder $query, ?User $user): Builder
    {
        return match ($user === null ? PermissionCatalog::SCOPE_ALL : self::scopeOf($user)) {
            PermissionCatalog::SCOPE_ALL => $query,
            PermissionCatalog::SCOPE_OWN => $query->where(fn ($q) => $q->where('requested_by', $user->id)->orWhere('borrower_user_id', $user->id)),
            default => $query->whereRaw('false'),
        };
    }

    /** Whether the user may see this form (scopeOf). */
    public static function covers(PartCheckout $checkout, User $user): bool
    {
        return match (self::scopeOf($user)) {
            PermissionCatalog::SCOPE_ALL => true,
            PermissionCatalog::SCOPE_OWN => self::isOwn($checkout, $user),
            default => false,
        };
    }

    /** Asked for by the user, or made out to them. */
    public static function isOwn(PartCheckout $checkout, User $user): bool
    {
        return (int) $checkout->requested_by === $user->id || (int) $checkout->borrower_user_id === $user->id;
    }
}
