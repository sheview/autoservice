<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use Carbon\CarbonInterface;

/**
 * Contracts that cover a job on a date, with their SLA, for the Service module:
 * active, the date inside their period, of the customer and (when given) including the asset.
 */
class CoveringContracts
{
    /**
     * @return list<array{id: int, contract_no: string, title: string, customer_id: int, service_window: string,
     *     slas: array<string, array{response_minutes: int, resolve_minutes: int}>}>
     */
    public function handle(?int $customerId, ?int $assetId = null, ?CarbonInterface $on = null): array
    {
        if ($customerId === null && $assetId === null) {
            return [];
        }

        $date = ($on ?? now())->toDateString();

        return Contract::query()
            ->with('slas')
            ->where('status', Contract::STATUS_ACTIVE)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($assetId, fn ($q, $id) => $q->whereHas('contractAssets', fn ($q) => $q->where('asset_id', $id)))
            ->orderBy('ends_on')
            ->get()
            ->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'contract_no' => $contract->contract_no,
                'title' => $contract->title,
                'customer_id' => $contract->customer_id,
                'service_window' => $contract->service_window,
                'slas' => $contract->slas->mapWithKeys(fn ($sla) => [$sla->priority => [
                    'response_minutes' => $sla->response_minutes,
                    'resolve_minutes' => $sla->resolve_minutes,
                ]])->all(),
            ])
            ->all();
    }
}
