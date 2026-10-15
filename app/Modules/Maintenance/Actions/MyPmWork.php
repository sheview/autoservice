<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Carbon\CarbonImmutable;

/**
 * The PM work orders a technician is given and has still to do (scheduled, in progress, sent back),
 * each on its day (the appointment, else the due date), with how many pieces of equipment are done.
 * For "my work" (Service) and the field pages.
 */
class MyPmWork
{
    public function __construct(private ListCustomers $listCustomers) {}

    /**
     * @return list<array{id: int, ulid: string, visit_no: string, title: string, customer: string|null, status: string,
     *     date: string, overdue: bool, onsite_at: string|null, items: int, done: int, href: string}>
     */
    public function handle(User $user): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $visits = PmVisit::query()
            ->where('assignee_id', $user->id)
            ->whereIn('status', [PmVisit::STATUS_SCHEDULED, ...PmVisit::WORKING_STATUSES])
            ->withCount(['items', 'items as done_count' => fn ($q) => $q->where('result', '!=', PmVisitItem::RESULT_PENDING)])
            ->orderByRaw('coalesce(scheduled_on, due_on)')
            ->orderBy('id')
            ->get();
        $customers = $visits->isEmpty() ? [] : collect($this->listCustomers->handle(withTrashed: true))->pluck('name', 'id')->all();

        return $visits->map(function (PmVisit $visit) use ($customers, $today) {
            $date = ($visit->scheduled_on ?? $visit->due_on)->toDateString();

            return [
                'id' => $visit->id,
                'ulid' => $visit->ulid,
                'visit_no' => $visit->visit_no,
                'title' => $visit->displayTitle(),
                'customer' => $customers[$visit->customer_id] ?? null,
                'status' => $visit->status,
                'date' => $date,
                'overdue' => $date < $today,
                'onsite_at' => $visit->onsite_at?->toIso8601String(),
                'items' => (int) $visit->items_count,
                'done' => (int) $visit->done_count,
                'href' => route('maintenance.field.visit', $visit),
            ];
        })->all();
    }
}
