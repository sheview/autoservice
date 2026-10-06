<?php

namespace App\Modules\RoomAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Document\Support\ThaiDate;
use App\Modules\Platform\Support\Modules;
use App\Modules\Reporting\Exports\ReportSheet;
use App\Modules\Reporting\Support\ReportPeriod;
use App\Modules\RoomAccess\Actions\RoomCalendar;
use App\Modules\RoomAccess\Actions\RoomVisitReport;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\ServerRoom;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The rooms' calendar (a month: who goes in where, freeze periods, holidays) and the report of
 * who went in and out (page, Excel, print, PDF). Whoever may see requests (room-access.view),
 * each seeing the requests in their scope.
 */
class RoomReportController extends Controller
{
    public function __construct(private Modules $modules) {}

    public function calendar(Request $request, RoomCalendar $calendar): Response
    {
        Gate::authorize('viewAny', RoomAccessRequest::class);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month'))
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->input('month').'-01')->startOfDay() : CarbonImmutable::today()->startOfMonth();
        $room = $request->filled('room') ? (string) $request->input('room') : null;

        return Inertia::render('RoomAccess/Calendar', [
            ...$calendar->handle($request->user(), $month, $room),
            'month' => $month->format('Y-m'),
            'room' => $room,
            'rooms' => $this->rooms(),
            'can' => ['create' => $request->user()->can('create', RoomAccessRequest::class)],
        ]);
    }

    public function index(Request $request, RoomVisitReport $report): Response
    {
        Gate::authorize('viewAny', RoomAccessRequest::class);
        [$period, $filters] = $this->filters($request);

        return Inertia::render('RoomAccess/Report', [
            'rows' => $report->handle($request->user(), $period->from, $period->to, $filters, 30),
            'filters' => [...$period->toArray(), ...$filters],
            'rooms' => $this->rooms(),
            'customers' => collect(app(CustomerLabelNames::class)->handle())->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values(),
            'contracts' => $this->modules->enabled('contract') ? app(ContractOptions::class)->handle($filters['contract_id']) : [],
        ]);
    }

    public function export(Request $request, RoomVisitReport $report): BinaryFileResponse
    {
        Gate::authorize('viewAny', RoomAccessRequest::class);
        [$period, $filters] = $this->filters($request);
        $rows = $report->handle($request->user(), $period->from, $period->to, $filters);
        $at = fn (?string $iso) => $iso ? CarbonImmutable::parse($iso)->timezone(config('app.timezone'))->format('Y-m-d H:i') : '';

        return Excel::download(new ReportSheet(__('ui.room_report.title'), array_map(fn (string $key) => __("ui.room_report.{$key}"), self::COLUMNS),
            array_map(fn (array $row) => [
                $at($row['entered_at']), $at($row['exited_at']), $row['minutes'], $row['request_no'], $row['room'], $row['customer'],
                $row['requester_name'], implode("\n", $row['people']), $row['purpose'], $row['work_summary'] ?? '',
                $row['ticket'] ?? '', $row['contract'] ?? '', $row['entered_by_name'] ?? '', $row['exited_by_name'] ?? '',
            ], $rows)), 'room-visits-'.$period->from->format('Ymd').'-'.$period->to->format('Ymd').'.xlsx');
    }

    /** The report as an A4 page for the browser to print (works without the PDF service). */
    public function print(Request $request, RoomVisitReport $report, TenantContext $context): View
    {
        return view('documents.room-visits', [...$this->sheet($request, $report, $context), 'forBrowser' => true]);
    }

    public function pdf(Request $request, RoomVisitReport $report, TenantContext $context, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        try {
            return $renderPdf->handle('documents.room-visits', $this->sheet($request, $report, $context), 'room-visits.pdf');
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    /** The Excel columns, in order (ui.room_report.*). */
    public const COLUMNS = [
        'entered_at', 'exited_at', 'minutes', 'request_no', 'room', 'customer', 'requester', 'people', 'purpose', 'work_summary',
        'ticket', 'contract', 'entered_by', 'exited_by',
    ];

    /** @return array<string, mixed> */
    private function sheet(Request $request, RoomVisitReport $report, TenantContext $context): array
    {
        Gate::authorize('viewAny', RoomAccessRequest::class);
        [$period, $filters] = $this->filters($request);

        return [
            'company' => $context->tenant()?->name,
            'period' => ThaiDate::format($period->from).' - '.ThaiDate::format($period->to),
            'room' => $filters['room'] ? ServerRoom::withTrashed()->where('ulid', $filters['room'])->value('name') : null,
            'customer' => $filters['customer_id'] ? (app(CustomerLabelNames::class)->handle()[$filters['customer_id']] ?? null) : null,
            'rows' => array_map(fn (array $row) => [
                ...$row,
                'entered' => ThaiDate::format(CarbonImmutable::parse($row['entered_at'])->timezone(config('app.timezone')), true),
                'exited' => $row['exited_at'] ? ThaiDate::format(CarbonImmutable::parse($row['exited_at'])->timezone(config('app.timezone')), true) : null,
            ], $report->handle($request->user(), $period->from, $period->to, $filters)),
        ];
    }

    /**
     * @return array{0: ReportPeriod, 1: array{search: string, room: string|null, customer_id: int|null, contract_id: int|null, direction: string}}
     */
    private function filters(Request $request): array
    {
        return [ReportPeriod::fromRequest($request), [
            'search' => $request->string('search')->trim()->limit(100, '')->value(),
            'room' => $request->filled('room') ? (string) $request->input('room') : null,
            'customer_id' => $request->integer('customer_id') ?: null,
            'contract_id' => $request->integer('contract_id') ?: null,
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ]];
    }

    /** @return list<array{ulid: string, name: string, customer: string}> */
    private function rooms(): array
    {
        $customers = app(CustomerLabelNames::class)->handle();

        return ServerRoom::query()->orderBy('name')->get()
            ->map(fn (ServerRoom $room) => ['ulid' => $room->ulid, 'name' => $room->name, 'customer' => $customers[$room->customer_id] ?? '-'])
            ->sortBy(fn (array $room) => $room['customer'].' '.$room['name'])->values()->all();
    }
}
