<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a contract and its SLA rows.
 * Moving the end date re-arms the "about to expire" e-mail.
 */
class SaveContract
{
    /**
     * @param  array<string, mixed>  $data  validated; value in satang;
     *                                      slas = [priority => ['response_minutes' => int, 'resolve_minutes' => int]] (missing priority = no SLA)
     */
    public function handle(?Contract $contract, array $data): Contract
    {
        return DB::transaction(function () use ($contract, $data) {
            $contract ??= new Contract;
            $slas = $data['slas'] ?? [];
            unset($data['slas']);

            $contract->fill($data);
            if ($contract->exists && $contract->isDirty('ends_on')) {
                $contract->expiry_notified_at = null;
            }
            $contract->save();

            $contract->slas()->whereNotIn('priority', array_keys($slas))->delete();
            foreach ($slas as $priority => $sla) {
                $contract->slas()->updateOrCreate(['priority' => $priority], $sla);
            }

            return $contract;
        });
    }
}
