<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use Illuminate\Support\Facades\DB;

/**
 * A work order made by hand, not cut from a plan: for one customer (and one of its contracts, or
 * none), with its own title, due date and the equipment to check. Saved as a draft to finish
 * later, or scheduled right away.
 */
class CreatePmWorkOrder
{
    public function __construct(
        private GeneratePmNumber $generateNumber,
        private AddPmVisitItems $addItems,
        private LogPmEvent $logEvent,
    ) {}

    /**
     * @param  array{customer_id: int, contract_id?: int|null, title: string, due_on: string, scheduled_on?: string|null,
     *     assignee_id?: int|null, asset_ids?: list<int>, draft?: bool}  $data  validated
     */
    public function handle(array $data, User $actor): PmVisit
    {
        return DB::transaction(function () use ($data, $actor) {
            $visit = PmVisit::create([
                'customer_id' => $data['customer_id'],
                'contract_id' => $data['contract_id'] ?? null,
                'visit_no' => $this->generateNumber->handle(),
                'title' => trim($data['title']),
                'due_on' => $data['due_on'],
                'scheduled_on' => $data['scheduled_on'] ?? null,
                'assignee_id' => $data['assignee_id'] ?? null,
                'created_by' => $actor->id,
                'status' => ! empty($data['draft']) ? PmVisit::STATUS_DRAFT : PmVisit::STATUS_SCHEDULED,
            ]);
            $this->logEvent->handle($visit, PmVisitEvent::TYPE_CREATED, $actor, null, null, $visit->status);

            if (! empty($data['asset_ids'])) {
                $this->addItems->handle($visit, $data['asset_ids'], $actor);
            }

            return $visit;
        });
    }
}
