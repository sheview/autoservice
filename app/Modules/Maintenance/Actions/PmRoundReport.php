<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Document\Support\Gotenberg;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmNumber;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * The round report for the customer: the approved work orders of a customer (or of one plan) due
 * within a period (by due date, as the PM report page), as one PDF — a cover listing them with their results, then each one's sheet
 * (the version given to the customer, else the newest approved; the customer's copy), joined by
 * the PDF service.
 */
class PmRoundReport
{
    public function __construct(
        private PmReportDocument $document,
        private Gotenberg $gotenberg,
        private TenantContext $context,
        private ListCustomers $listCustomers,
    ) {}

    /**
     * @param  array{customer_id?: int|null, plan_id?: int|null, from: string, to: string}  $filters
     * @return array{pdf: string, file: string, count: int}
     *
     * @throws ValidationException when no approved work order falls within
     */
    public function handle(array $filters): array
    {
        $visits = PmVisit::query()
            ->whereIn('status', PmVisit::REPORTED_STATUSES)
            ->where('current_version', '>', 0)
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['plan_id'] ?? null, fn ($q, $id) => $q->where('pm_plan_id', $id))
            ->whereBetween('due_on', [$filters['from'], $filters['to']])
            ->withCount([
                'items',
                'items as passed_count' => fn ($q) => $q->where('result', PmVisitItem::RESULT_PASSED),
                'items as failed_count' => fn ($q) => $q->where('result', PmVisitItem::RESULT_FAILED),
                'items as not_inspected_count' => fn ($q) => $q->where('result', PmVisitItem::RESULT_NOT_INSPECTED),
            ])
            ->orderBy('due_on')
            ->orderBy('visit_no')
            ->get();
        if ($visits->isEmpty()) {
            throw ValidationException::withMessages(['report' => __('maintenance.round_report.empty')]);
        }

        $customers = collect($this->listCustomers->handle(withTrashed: true))->keyBy('id');
        $customerId = $filters['customer_id'] ?? $visits->first()->customer_id;
        $customer = $customers[$customerId]['name'] ?? null;
        $tenant = $this->context->tenant();
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);

        $pdfs = [$this->gotenberg->htmlToPdf(view('documents.pm-round-cover', [
            'company' => $tenant?->name,
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'customer' => $customer,
            'from' => $filters['from'],
            'to' => $filters['to'],
            'rows' => $visits->map(fn (PmVisit $visit) => [
                'number' => PmNumber::shown($visit->visit_no, $visit->tenant_id),
                'title' => $visit->displayTitle(),
                'customer' => $customers[$visit->customer_id]['name'] ?? null,
                'date' => $visit->due_on->toDateString(),
                'completed_at' => $visit->completed_at,
                'total' => $visit->items_count,
                'passed' => $visit->passed_count,
                'failed' => $visit->failed_count,
                'not_inspected' => $visit->not_inspected_count,
                'signed' => $visit->locked_at !== null,
            ])->all(),
        ])->render())];

        foreach ($visits as $visit) {
            $version = $visit->versions()->where('version', $visit->published_version ?: $visit->current_version)->first();
            $pdfs[] = $this->gotenberg->htmlToPdf(
                view('documents.pm-sheet', $this->document->handle($visit, $version, null, customerCopy: true))->render(),
                $this->document->footer($visit, $version),
            );
        }

        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', 'PM-round-'.($customers[$customerId]['code'] ?? 'all').'-'.$filters['from'].'-'.$filters['to']);

        return ['pdf' => $this->gotenberg->mergePdfs($pdfs), 'file' => $name.'.pdf', 'count' => $visits->count()];
    }
}
