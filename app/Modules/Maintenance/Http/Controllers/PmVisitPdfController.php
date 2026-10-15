<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Maintenance\Actions\PmReportDocument;
use App\Modules\Maintenance\Actions\PmReportVersionFor;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The PM report PDFs of a work order (Gotenberg, A4, Buddhist year, page numbers), from one set
 * of data (PmReportDocument): the consolidated report, or the report of one piece of equipment
 * (its item id in the report). ?version=N for an earlier approved version (staff); a customer
 * account only ever gets the published one (PmReportVersionFor). ?download=1 saves the file.
 */
class PmVisitPdfController extends Controller
{
    public function __construct(private PmReportDocument $document, private PmReportVersionFor $versionFor, private RenderPdf $renderPdf) {}

    public function consolidated(Request $request, PmVisit $visit): HttpResponse|RedirectResponse
    {
        return $this->render($request, $visit, null);
    }

    public function equipment(Request $request, PmVisit $visit, int $item): HttpResponse|RedirectResponse
    {
        return $this->render($request, $visit, $item);
    }

    private function render(Request $request, PmVisit $visit, ?int $itemId): HttpResponse|RedirectResponse
    {
        Gate::authorize('view', $visit);

        $version = $this->versionFor->handle($visit, $request->user(), $request->integer('version') ?: null);
        $data = $this->document->handle($visit, $version, $itemId);
        $assetCode = $itemId !== null ? ($data['items'][0]['asset']['asset_code'] ?? null) : null;

        try {
            return $this->renderPdf->handle(
                $itemId === null ? 'documents.pm-report' : 'documents.pm-equipment-report',
                $data,
                $this->document->fileName($visit, $version, $assetCode),
                $this->document->footer($visit, $version, $assetCode),
                $request->boolean('download'),
            );
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }
}
