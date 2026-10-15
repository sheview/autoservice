<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmSignature;
use App\Modules\Maintenance\Models\PmSignLink;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Platform\Support\Modules;
use App\Modules\Survey\Actions\RecordPmVisitSurvey;
use Illuminate\Support\Facades\DB;

/**
 * The customer signs a work order — on the technician's device, or on their own phone through a
 * sign link (which is then used up) — with the satisfaction they ticked, kept as the work order's
 * survey (Survey module). Their signature locks the results (SignPmVisit).
 */
class SignPmVisitByCustomer
{
    public function __construct(private SignPmVisit $sign, private RecordPmVisitSurvey $recordSurvey, private MovePmVisit $move) {}

    /**
     * @param  array{image: string, name: string, position?: string|null, phone?: string|null, email?: string|null,
     *     satisfaction?: string|null, comment?: string|null}  $data  validated
     * @param  array{ip?: string|null, user_agent?: string|null}  $client
     */
    public function handle(PmVisit $visit, array $data, ?User $actor, array $client = [], ?PmSignLink $link = null): PmSignature
    {
        return DB::transaction(function () use ($visit, $data, $actor, $client, $link) {
            if ($link !== null) {
                $link = PmSignLink::query()->lockForUpdate()->findOrFail($link->id);
                abort_unless($link->isActive(), 404);
                $link->update(['used_at' => now()]);
            }

            $signature = $this->sign->handle($visit, PmSignature::ROLE_CUSTOMER, $data, $actor, $client);

            if (filled($data['satisfaction'] ?? null) && app(Modules::class)->enabled('survey')) {
                $this->recordSurvey->handle([
                    'id' => $visit->id,
                    'ulid' => $visit->ulid,
                    'visit_no' => $visit->visit_no,
                    'title' => $visit->displayTitle(),
                    'customer_id' => $visit->customer_id,
                    'assignee_id' => $visit->assignee_id,
                ], $data['satisfaction'], $data['comment'] ?? null, $signature->name, $actor?->id);
            }

            // Without an approval step, work waiting only for this signature is approved now.
            $visit->refresh();
            if ($visit->status === PmVisit::STATUS_PENDING_APPROVAL && ! PmSettings::of()->approvalStep) {
                $this->move->approveBySystem($visit, $actor);
            }

            return $signature;
        });
    }
}
