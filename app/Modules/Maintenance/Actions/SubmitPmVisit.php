<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmSignature;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Maintenance\Support\PmWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The technician hands the field work in: every piece of equipment has a result, the summary is
 * written and the technician signs (pm_signatures, required when the company says so; one from an
 * earlier hand-in still counts). The customer may sign on the same device — which locks the results —
 * or later on their own phone through a sign link (CreatePmSignLink). It goes to review, or straight to
 * the approver when the company has no review step, and is approved at once without an approval step.
 */
class SubmitPmVisit
{
    public function __construct(
        private LogPmEvent $logEvent,
        private SignPmVisit $sign,
        private SignPmVisitByCustomer $signByCustomer,
        private MovePmVisit $move,
    ) {}

    /**
     * @param  array{summary?: string|null, technician_signature?: string|null, customer_signature?: string|null,
     *     customer_signer_name?: string|null, customer_signer_position?: string|null, customer_signer_phone?: string|null,
     *     customer_signer_email?: string|null, satisfaction?: string|null, satisfaction_comment?: string|null}  $data  signatures as data:image/png;base64,…
     * @param  array{ip?: string|null, user_agent?: string|null}  $client
     */
    public function handle(PmVisit $visit, User $actor, array $data, array $client = []): PmVisit
    {
        $settings = PmSettings::of();

        return DB::transaction(function () use ($visit, $actor, $data, $settings, $client) {
            $visit = PmVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if (! PmWorkflow::allows($visit, 'submit') || $visit->isLocked()) {
                throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
            }

            $pending = $visit->items()->where('result', PmVisitItem::RESULT_PENDING)->count();
            if ($pending > 0 || ! $visit->items()->exists()) {
                throw ValidationException::withMessages(['visit' => $pending > 0
                    ? __('maintenance.visits.items_pending', ['count' => $pending])
                    : __('maintenance.visits.no_items')]);
            }

            if (filled($data['technician_signature'] ?? null)) {
                $this->sign->handle($visit, PmSignature::ROLE_ENGINEER, ['image' => $data['technician_signature'], 'name' => $actor->name], $actor, $client);
            } elseif ($settings->requireTechnicianSignature && ! $visit->signatures()->where('role', PmSignature::ROLE_ENGINEER)->exists()) {
                throw ValidationException::withMessages(['technician_signature' => __('maintenance.visits.signature_required.technician_signature')]);
            }
            if (filled($data['customer_signature'] ?? null) && blank($data['customer_signer_name'] ?? null)) {
                throw ValidationException::withMessages(['customer_signer_name' => __('maintenance.visits.signer_required')]);
            }

            $from = $visit->status;
            $to = PmWorkflow::target('submit', $settings);
            $visit->update([
                'status' => $to,
                'completed_at' => now(),
                'submitted_by' => $actor->id,
                'summary' => filled($data['summary'] ?? null) ? trim($data['summary']) : $visit->summary,
            ]);
            $this->logEvent->handle($visit, 'submit', $actor, null, $from, $to);

            // Signed by the customer on this device too: their signature locks the results.
            if (filled($data['customer_signature'] ?? null)) {
                $this->signByCustomer->handle($visit, [
                    'image' => $data['customer_signature'],
                    'name' => $data['customer_signer_name'] ?? '',
                    'position' => $data['customer_signer_position'] ?? null,
                    'phone' => $data['customer_signer_phone'] ?? null,
                    'email' => $data['customer_signer_email'] ?? null,
                    'satisfaction' => $data['satisfaction'] ?? null,
                    'comment' => $data['satisfaction_comment'] ?? null,
                ], $actor, $client);
                $visit->refresh();
            }
            if ($to === PmVisit::STATUS_PENDING_APPROVAL && ! $settings->approvalStep) {
                $this->move->approveBySystem($visit, $actor);
            }

            activity()->performedOn($visit)->causedBy($actor)->event('pm_submit')
                ->withProperties(['visit_no' => $visit->visit_no, 'from' => $from, 'to' => $visit->status])
                ->log(__('maintenance.log.submit', ['no' => $visit->visit_no]));

            return $visit;
        });
    }
}
