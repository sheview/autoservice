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
 * The management report: figures of the whole company, so it needs reports.view (and
 * reports.export for the Excel workbook), not the permissions of each module it sums up. With
 * scope customer, only that customer's figures (BuildReport).
 */
class ReportController extends Controller
{
    public const PERMISSION = 'reports.view';

    public const EXPORT_PERMISSION = 'reports.export';

    public function index(Request $request, BuildReport $buildReport): Response
    {
        $user = $request->user();
        abort_unless($user->can(self::PERMISSION), 403);

        return Inertia::render('Reporting/Index', [
            'report' => $buildReport->handle(ReportPeriod::fromRequest($request), $user, self::PERMISSION),
            'can' => ['export' => $user->can(self::EXPORT_PERMISSION)],
        ]);
    }

    /**
     * The same report (same period) as an Excel workbook.
     */
    public function export(Request $request, BuildReport $buildReport): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->can(self::EXPORT_PERMISSION), 403);

        $period = ReportPeriod::fromRequest($request);
        ['from' => $from, 'to' => $to] = $period->toArray();

        return Excel::download(new ReportExport($buildReport->handle($period, $user, self::EXPORT_PERMISSION)), "report-{$from}-{$to}.xlsx");
    }
}
