<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Service\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The ticket list query: the user's scope + filters + sort.
 * Users without branch.all see tickets of their branch, without a branch, or assigned to them.
 */
class SearchTickets
{
    public const SORTABLE = ['ticket_no', 'created_at', 'updated_at', 'resolve_due_at', 'priority'];

    /** "open" = every running status, "all" = everything, or one status */
    public const STATUS_FILTERS = ['open', 'all', ...Ticket::STATUSES];

    public const SLA_FILTERS = ['breached', 'due_soon'];

    /** "due_soon" = the resolve time ends within this many hours. */
    public const DUE_SOON_HOURS = 4;

    /** Priority order for sorting, most urgent first. */
    private const PRIORITY_ORDER = "array_position(array['critical','high','medium','low']::varchar[], priority)";

    /**
     * @return array{search: string, status: string, priority: string|null, assignee: string|null,
     *     customer_id: int|null, sla: string|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $assignee = (string) $request->input('assignee', '');

        return [
            'search' => $request->string('search')->trim()->value(),
            // The list opens on running tickets.
            'status' => in_array($request->input('status'), self::STATUS_FILTERS, true) ? $request->input('status') : 'open',
            'priority' => in_array($request->input('priority'), Ticket::PRIORITIES, true) ? $request->input('priority') : null,
            'assignee' => in_array($assignee, ['me', 'none'], true) || ctype_digit($assignee) ? $assignee : null,
            'customer_id' => $request->integer('customer_id') ?: null,
            'sla' => in_array($request->input('sla'), self::SLA_FILTERS, true) ? $request->input('sla') : null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<Ticket>
     */
    public function handle(User $user, array $filters): Builder
    {
        $now = now();
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? 'open';
        $assignee = (string) ($filters['assignee'] ?? '');
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        // SLA filters look at tickets whose clocks are running (not on hold, not done).
        $running = fn (Builder $q) => $q->whereIn('status', [Ticket::STATUS_NEW, Ticket::STATUS_ASSIGNED, Ticket::STATUS_IN_PROGRESS]);

        return self::visibleTo(Ticket::query(), $user)
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('ticket_no', 'ilike', "%{$search}%")
                ->orWhere('title', 'ilike', "%{$search}%")
                ->orWhere('contact_name', 'ilike', "%{$search}%")))
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('status', Ticket::OPEN_STATUSES))
            ->when(! in_array($status, ['open', 'all'], true), fn (Builder $q) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, $priority) => $q->where('priority', $priority))
            ->when($assignee === 'me', fn (Builder $q) => $q->where('assignee_id', $user->id))
            ->when($assignee === 'none', fn (Builder $q) => $q->whereNull('assignee_id'))
            ->when(ctype_digit($assignee), fn (Builder $q) => $q->where('assignee_id', (int) $assignee))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when(($filters['sla'] ?? null) === 'breached', fn (Builder $q) => $running($q)->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('responded_at')->where('response_due_at', '<', $now))
                ->orWhere('resolve_due_at', '<', $now)))
            ->when(($filters['sla'] ?? null) === 'due_soon', fn (Builder $q) => $running($q)
                ->whereBetween('resolve_due_at', [$now, $now->copy()->addHours(self::DUE_SOON_HOURS)]))
            // "desc" priority = most urgent first
            ->when(($filters['sort'] ?? null) === 'priority',
                fn (Builder $q) => $q->orderByRaw(self::PRIORITY_ORDER.' '.($direction === 'desc' ? 'asc' : 'desc')),
                fn (Builder $q) => $q->orderBy($filters['sort'] ?? 'created_at', $direction))
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        return $query->unless($user->can(PermissionCatalog::ALL_BRANCHES), fn (Builder $q) => $q->where(fn ($q) => $q
            ->whereNull('branch_id')
            ->orWhere('assignee_id', $user->id)
            ->when($user->branch_id, fn ($q, $branchId) => $q->orWhere('branch_id', $branchId))));
    }
}
