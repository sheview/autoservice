<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetLabels;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Inventory\Actions\IssuedPartsReport;
use App\Modules\Platform\Support\Modules;
use App\Modules\Reporting\Exports\ReportSheet;
use App\Modules\Reporting\Support\ReportPeriod;
use App\Modules\Service\Actions\TicketIdsForReport;
use App\Modules\Service\Actions\TicketReportLabels;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Report "parts replaced / issued": what left stock in a period, with the serial numbers of the
 * pieces, filtered by MA contract, customer and a search (part or serial); as a page, an Excel
 * workbook and a PDF. reports.view (reports.export for the files), with the Inventory module on.
 */
class PartsIssuedController extends Controller
{
    public function __construct(private Modules $modules) {}

    public function index(Request $request, IssuedPartsReport $report): Response
    {
        $this->authorizeView($request, ReportController::PERMISSION);
        [$period, $filters] = $this->filters($request);

        $page = $report->handle($period->from, $period->to, $this->reportFilters($filters), 30);
        $page->setCollection(collect($this->labelled($page->getCollection()->all())));

        return Inertia::render('Reporting/PartsIssued', [
            'rows' => $page,
            'filters' => [...$period->toArray(), ...$filters],
            'contracts' => $this->modules->enabled('contract') ? app(ContractOptions::class)->handle($filters['contract_id']) : [],
            'customers' => $this->modules->enabled('contract') ? app(ListCustomers::class)->handle() : [],
            'can' => ['export' => $request->user()->can(ReportController::EXPORT_PERMISSION)],
        ]);
    }

    public function export(Request $request, IssuedPartsReport $report): BinaryFileResponse
    {
        $this->authorizeView($request, ReportController::EXPORT_PERMISSION);
        [$period, $filters] = $this->filters($request);
        $rows = $this->labelled($report->handle($period->from, $period->to, $this->reportFilters($filters)));

        return Excel::download(new ReportSheet(__('ui.parts_issued.title'), [
            __('ui.parts_issued.date'), __('ui.parts_issued.part_code'), __('ui.parts_issued.part'), __('ui.parts_issued.brand_model'),
            __('ui.parts_issued.quantity'), __('ui.parts_issued.serials'), __('ui.parts_issued.returned'), __('ui.parts_issued.ticket'),
            __('ui.parts_issued.customer'), __('ui.parts_issued.contract'), __('ui.parts_issued.asset'), __('ui.parts_issued.by'), __('ui.parts_issued.value'),
        ], array_map(fn (array $row) => [
            substr($row['at'], 0, 10), $row['part']['code'] ?? '', $row['part']['name'] ?? '',
            trim(($row['part']['brand'] ?? '').' '.($row['part']['part_number'] ?? '')), $row['quantity'],
            implode("\n", $row['serials']), implode("\n", $row['returned']), $row['ticket']['ticket_no'] ?? $row['reference'] ?? '',
            $row['customer'] ?? '', $row['contract'] ?? '', $row['asset'] ?? '', $row['user_name'] ?? '', $row['value'],
        ], $rows)), 'parts-issued-'.$period->from->format('Ymd').'-'.$period->to->format('Ymd').'.xlsx');
    }

    /** The report as an A4 page for the browser to print (works without the PDF service). */
    public function print(Request $request, IssuedPartsReport $report, TenantContext $context): View
    {
        return view('documents.parts-issued', [...$this->sheet($request, $report, $context), 'forBrowser' => true]);
    }

    public function pdf(Request $request, IssuedPartsReport $report, TenantContext $context, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        $sheet = $this->sheet($request, $report, $context);

        try {
            return $renderPdf->handle('documents.parts-issued', $sheet, 'parts-issued.pdf');
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    /** @return array<string, mixed> */
    private function sheet(Request $request, IssuedPartsReport $report, TenantContext $context): array
    {
        $this->authorizeView($request, ReportController::EXPORT_PERMISSION);
        [$period, $filters] = $this->filters($request);

        return [
            'company' => $context->tenant()?->name,
            'period' => $period,
            'contract' => $filters['contract_id'] && $this->modules->enabled('contract')
                ? (app(ContractLabels::class)->handle([$filters['contract_id']])[$filters['contract_id']] ?? null) : null,
            'customer' => $filters['customer_id'] && $this->modules->enabled('contract')
                ? (app(CustomerLabelNames::class)->handle()[$filters['customer_id']] ?? null) : null,
            'rows' => $this->labelled($report->handle($period->from, $period->to, $this->reportFilters($filters))),
        ];
    }

    private function authorizeView(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission) && $request->user()->customer_id === null, 403);
        abort_unless($this->modules->enabled('inventory'), 404);
    }

    /**
     * @return array{0: ReportPeriod, 1: array{search: string, contract_id: int|null, customer_id: int|null}}
     */
    private function filters(Request $request): array
    {
        return [ReportPeriod::fromRequest($request), [
            // The text box: part code, name or serial.
            'search' => $request->string('search')->trim()->limit(100, '')->value(),
            'contract_id' => $request->integer('contract_id') ?: null,
            'customer_id' => $request->integer('customer_id') ?: null,
        ]];
    }

    /**
     * Contract and customer narrow the report to their tickets (Service module).
     *
     * @param  array{search: string, contract_id: int|null, customer_id: int|null}  $filters
     * @return array{q: string, ticket_ids: list<int>|null}
     */
    private function reportFilters(array $filters): array
    {
        $narrowed = $filters['contract_id'] || $filters['customer_id'];

        return [
            'q' => $filters['search'],
            'ticket_ids' => ! $narrowed ? null : ($this->modules->enabled('service')
                ? app(TicketIdsForReport::class)->handle($filters['contract_id'], $filters['customer_id']) : []),
        ];
    }

    /**
     * Rows with their ticket, customer, contract and device named (from their modules).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function labelled(array $rows): array
    {
        $tickets = $this->modules->enabled('service') ? app(TicketReportLabels::class)->handle(array_column($rows, 'ticket_id')) : [];
        $contractOn = $this->modules->enabled('contract');
        $contracts = $contractOn ? app(ContractLabels::class)->handle(array_column($tickets, 'contract_id')) : [];
        $customers = $contractOn && $tickets !== [] ? app(CustomerLabelNames::class)->handle() : [];
        $assets = $this->modules->enabled('asset') ? app(AssetLabels::class)->handle(array_column($tickets, 'asset_id')) : [];

        return array_map(function (array $row) use ($tickets, $contracts, $customers, $assets) {
            $ticket = $tickets[$row['ticket_id']] ?? null;
            $contract = $ticket ? ($contracts[$ticket['contract_id']] ?? null) : null;
            $asset = $ticket ? ($assets[$ticket['asset_id']] ?? null) : null;

            return $row + [
                'ticket' => $ticket ? ['ulid' => $ticket['ulid'], 'ticket_no' => $ticket['ticket_no']] : null,
                'customer' => $ticket ? ($customers[$ticket['customer_id']] ?? null) : null,
                'contract' => $contract ? $contract['contract_no'] : null,
                'asset' => $asset ? $asset['asset_code'].' '.$asset['name'] : null,
            ];
        }, $rows);
    }
}
