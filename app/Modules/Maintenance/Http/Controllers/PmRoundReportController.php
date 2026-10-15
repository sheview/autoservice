<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Maintenance\Actions\PmRoundReport;
use App\Modules\Maintenance\Models\PmPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "ออกรายงานสรุปรอบ": the approved work orders of a customer or a plan within a period as one PDF
 * (PmRoundReport) — or, with ?print=1, as one page that opens the browser's print dialog (no PDF
 * service needed). Staff who may see PM reports.
 */
class PmRoundReportController extends Controller
{
    public function __invoke(Request $request, PmRoundReport $report): Response|RedirectResponse
    {
        Gate::authorize('pm-reports.view');
        abort_if($request->user()->customer_id !== null, 403);

        $data = $request->validate([
            'customer_id' => ['nullable', 'required_without:plan', 'integer', Rule::exists('customers', 'id')],
            'plan' => ['nullable', 'integer', Rule::exists('pm_plans', 'id')->whereNull('deleted_at')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ], [], __('maintenance.fields'));
        if (isset($data['plan'])) {
            Gate::authorize('view', PmPlan::findOrFail($data['plan']));
        }

        $filters = [
            'customer_id' => $data['customer_id'] ?? null,
            'plan_id' => $data['plan'] ?? null,
            'from' => $data['from'],
            'to' => $data['to'],
        ];

        try {
            if ($request->boolean('print')) {
                $printable = $report->printable($filters);

                return response()->view('documents.pm-round-print', [
                    'cover' => $printable['cover'], 'sheets' => $printable['sheets'], 'forBrowser' => true,
                ]);
            }
            $made = $report->handle($filters);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }

        return response($made['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$made['file'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
