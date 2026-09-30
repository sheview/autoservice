<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Actions\BuildReport;
use App\Modules\Reporting\Exports\ReportExport;
use App\Modules\Reporting\Support\ReportPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The management report: figures of the whole tenant, so it needs report.view (office staff),
 * not the permissions of each module it sums up.
 */
class ReportController extends Controller
{
    public const PERMISSION = 'report.view';

    public function index(Request $request, BuildReport $buildReport): Response
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);

        return Inertia::render('Reporting/Index', [
            'report' => $buildReport->handle(ReportPeriod::fromRequest($request)),
        ]);
    }

    /**
     * The same report (same period) as an Excel workbook.
     */
    public function export(Request $request, BuildReport $buildReport): BinaryFileResponse
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);

        $period = ReportPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return Excel::download(new ReportExport($buildReport->handle($period)), "report-{$from}-{$to}.xlsx");
    }
}
