<?php

namespace App\Modules\Survey\Actions;

use App\Modules\Survey\Models\TicketSurvey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The survey list query: search (ticket, comment, who answered) + filters + sort.
 */
class SearchTicketSurveys
{
    public const SORTABLE = ['created_at', 'answered_at', 'score', 'ticket_no'];

    public const STATUSES = ['answered', 'pending'];

    /**
     * @return array{search: string, status: string|null, score: int|null, customer_id: int|null,
     *     assignee_id: int|null, sort: string, direction: string}
     */
    public static function filtersFrom(Request $request): array
    {
        $score = $request->integer('score');

        return [
            'search' => $request->string('search')->trim()->value(),
            'status' => in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : null,
            'score' => $score >= TicketSurvey::MIN_SCORE && $score <= TicketSurvey::MAX_SCORE ? $score : null,
            'customer_id' => $request->integer('customer_id') ?: null,
            'assignee_id' => $request->integer('assignee_id') ?: null,
            'sort' => in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filtersFrom()
     * @return Builder<TicketSurvey>
     */
    public function handle(array $filters): Builder
    {
        $search = $filters['search'] ?? '';
        $direction = $filters['direction'] ?? 'desc';

        return TicketSurvey::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('ticket_no', 'ilike', "%{$search}%")
                ->orWhere('ticket_title', 'ilike', "%{$search}%")
                ->orWhere('comment', 'ilike', "%{$search}%")
                ->orWhere('answered_name', 'ilike', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $status === 'answered'
                ? $q->whereNotNull('answered_at')
                : $q->whereNull('answered_at'))
            ->when($filters['score'] ?? null, fn (Builder $q, $score) => $q->where('score', $score))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when($filters['assignee_id'] ?? null, fn (Builder $q, $id) => $q->where('assignee_id', $id))
            // Unanswered surveys have no score or answer time: keep them at the end either way.
            ->orderByRaw(($filters['sort'] ?? 'created_at').' '.$direction.' nulls last')
            ->orderBy('id', $direction);
    }
}
