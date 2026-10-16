<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Actions\ExportPmResultSheet;
use App\Modules\Maintenance\Actions\ImportPmResultSheet;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A work order's results as Excel (ExportPmResultSheet), and the same sheet filled in brought back
 * (ImportPmResultSheet). Staff who may see the work order export it; who performs it imports.
 */
class PmResultSheetController extends Controller
{
    public function export(Request $request, PmVisit $visit, ExportPmResultSheet $export): BinaryFileResponse
    {
        Gate::authorize('view', $visit);
        abort_if($request->user()->customer_id !== null, 403);

        ['path' => $path, 'file' => $file] = $export->handle($visit);

        return response()->download($path, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    public function import(Request $request, PmVisit $visit, ImportPmResultSheet $import): RedirectResponse
    {
        Gate::authorize('perform', $visit);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120']], [], ['file' => __('maintenance.result_sheet.file')]);

        ['saved' => $saved, 'skipped' => $skipped] = $import->handle($visit, $request->user(), $data['file']);

        $response = back()->with('success', __('maintenance.result_sheet.imported', ['count' => $saved]));

        return $skipped === [] ? $response : $response->withErrors(['file' => __('maintenance.result_sheet.skipped', ['list' => implode(' · ', $skipped)])]);
    }
}
