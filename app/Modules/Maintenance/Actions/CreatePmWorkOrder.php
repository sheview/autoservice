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
        private AssignPmTemplateToAsset $assignTemplate,
    ) {}

    /**
     * $data['templates'] is a form picked by hand (template code by asset id) over the one the asset
     * would get; with remember_templates the assets keep it for later work orders.
     *
     * @param  array{customer_id: int, contract_id?: int|null, title: string, due_on: string, scheduled_on?: string|null,
     *     assignee_id?: int|null, asset_ids?: list<int>, templates?: array<int, string|null>, remember_templates?: bool, draft?: bool}  $data  validated
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
                // A form picked by hand counts only for equipment that is on the work order.
                $chosen = array_intersect_key(array_filter($data['templates'] ?? []), array_flip($data['asset_ids']));
                $this->addItems->handle($visit, $data['asset_ids'], $actor, $chosen);
                if (! empty($data['remember_templates'])) {
                    foreach ($chosen as $assetId => $code) {
                        $this->assignTemplate->handle((int) $assetId, (string) $code);
                    }
                }
            }

            return $visit;
        });
    }
}
