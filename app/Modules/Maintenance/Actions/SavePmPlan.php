<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a PM plan for a contract (period and customer copied from the contract) and schedules
 * its rounds, or updates one:
 *   - a new assignee is passed on to the rounds that have not started and had the old one (or none)
 *   - a new interval re-schedules every round, which is only allowed while none has started
 */
class SavePmPlan
{
    public function __construct(
        private ContractDetails $contractDetails,
        private SchedulePmVisits $scheduleVisits,
    ) {}

    /**
     * @param  array{contract_id?: int, title: string, interval_months: int, assignee_id?: int|null, notes?: string|null}  $data
     *                                                                                                                            validated; contract_id only when creating
     */
    public function handle(?PmPlan $plan, array $data): PmPlan
    {
        return DB::transaction(fn () => $plan === null ? $this->create($data) : $this->update($plan, $data));
    }

    private function create(array $data): PmPlan
    {
        $contract = $this->contractDetails->handle([$data['contract_id']])[$data['contract_id']];

        $plan = PmPlan::create([
            'contract_id' => $contract['id'],
            'customer_id' => $contract['customer_id'],
            'title' => $data['title'],
            'interval_months' => $data['interval_months'],
            'starts_on' => $contract['starts_on'],
            'ends_on' => $contract['ends_on'],
            'assignee_id' => $data['assignee_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->scheduleVisits->handle($plan);

        return $plan;
    }

    private function update(PmPlan $plan, array $data): PmPlan
    {
        $oldAssignee = $plan->assignee_id;
        $plan->fill([
            'title' => $data['title'],
            'interval_months' => $data['interval_months'],
            'assignee_id' => $data['assignee_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($plan->isDirty('interval_months')) {
            if ($plan->visits()->where('status', '!=', PmVisit::STATUS_SCHEDULED)->exists()) {
                throw ValidationException::withMessages(['interval_months' => __('maintenance.plans.interval_locked')]);
            }
            $plan->save();
            $plan->visits()->each(fn (PmVisit $visit) => $visit->delete());
            $this->scheduleVisits->handle($plan);

            return $plan;
        }

        $plan->save();

        if ($plan->assignee_id !== $oldAssignee) {
            $plan->visits()
                ->where('status', PmVisit::STATUS_SCHEDULED)
                ->where(fn ($q) => $q->whereNull('assignee_id')->when($oldAssignee, fn ($q, $id) => $q->orWhere('assignee_id', $id)))
                ->update(['assignee_id' => $plan->assignee_id, 'reminded_at' => null]);
        }

        return $plan;
    }
}
