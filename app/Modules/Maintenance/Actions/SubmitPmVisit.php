<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Maintenance\Support\PmWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The technician hands the field work in: every piece of equipment has a result, the summary is
 * written and the signatures the company asks for (PmSettings) are drawn. It goes to review, or
 * straight to the approver when the company has no review step. Handing in again after a
 * return replaces the signatures.
 */
class SubmitPmVisit
{
    public function __construct(private LogPmEvent $logEvent) {}

    /**
     * @param  array{summary?: string|null, technician_signature?: string|null, customer_signature?: string|null,
     *     customer_signer_name?: string|null, customer_signer_position?: string|null}  $data  signatures as data:image/png;base64,…
     */
    public function handle(PmVisit $visit, User $actor, array $data): PmVisit
    {
        $settings = PmSettings::of();

        return DB::transaction(function () use ($visit, $actor, $data, $settings) {
            $visit = PmVisit::query()->lockForUpdate()->findOrFail($visit->id);
            if (! PmWorkflow::allows($visit, 'submit')) {
                throw ValidationException::withMessages(['visit' => __('maintenance.visits.not_in_progress')]);
            }

            $pending = $visit->items()->where('result', PmVisitItem::RESULT_PENDING)->count();
            if ($pending > 0 || ! $visit->items()->exists()) {
                throw ValidationException::withMessages(['visit' => $pending > 0
                    ? __('maintenance.visits.items_pending', ['count' => $pending])
                    : __('maintenance.visits.no_items')]);
            }

            $this->sign($visit, $actor, $data, $settings);

            $from = $visit->status;
            $to = PmWorkflow::target('submit', $settings);
            $visit->update([
                'status' => $to,
                'completed_at' => now(),
                'submitted_by' => $actor->id,
                'summary' => filled($data['summary'] ?? null) ? trim($data['summary']) : $visit->summary,
            ]);
            $this->logEvent->handle($visit, 'submit', $actor, null, $from, $to);

            activity()->performedOn($visit)->causedBy($actor)->event('pm_submit')
                ->withProperties(['visit_no' => $visit->visit_no, 'from' => $from, 'to' => $to])
                ->log(__('maintenance.log.submit', ['no' => $visit->visit_no]));

            return $visit;
        });
    }

    private function sign(PmVisit $visit, User $actor, array $data, PmSettings $settings): void
    {
        $signatures = [
            PmVisit::TECHNICIAN_SIGNATURE => [$data['technician_signature'] ?? null, $actor->name, null, $settings->requireTechnicianSignature],
            PmVisit::CUSTOMER_SIGNATURE => [
                $data['customer_signature'] ?? null, $data['customer_signer_name'] ?? null, $data['customer_signer_position'] ?? null,
                $settings->requireCustomerSignature,
            ],
        ];

        foreach ($signatures as $collection => [$image, $signer, $position, $required]) {
            $png = is_string($image) && str_starts_with($image, 'data:image/png;base64,') ? base64_decode(substr($image, 22), true) : false;
            if ($png === false || $png === '') {
                // Required and not drawn now: one drawn on an earlier hand-in still counts.
                if ($required && ! $visit->hasMedia($collection)) {
                    throw ValidationException::withMessages([$collection => __('maintenance.visits.signature_required.'.$collection)]);
                }

                continue;
            }
            if ($collection === PmVisit::CUSTOMER_SIGNATURE && blank($signer)) {
                throw ValidationException::withMessages(['customer_signer_name' => __('maintenance.visits.signer_required')]);
            }

            $visit->addMediaFromString($png)
                ->usingFileName($collection.'.png')
                ->withCustomProperties(['signer' => $signer, 'position' => $position, 'signed_at' => now()->toIso8601String(), 'by' => $actor->id])
                ->toMediaCollection($collection);
            $this->logEvent->handle($visit, PmVisitEvent::TYPE_SIGNED, $actor, null, null, null, ['collection' => $collection, 'signer' => $signer]);
        }
    }
}
