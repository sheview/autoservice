<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Maintenance\Actions\PmVisitSheet;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The PM report of a round as a PDF (Gotenberg, Buddhist year), for whoever may see the round.
 */
class PmVisitPdfController extends Controller
{
    public function __invoke(PmVisit $visit, PmVisitSheet $sheet, RenderPdf $renderPdf): Response|RedirectResponse
    {
        Gate::authorize('view', $visit);

        try {
            return $renderPdf->handle('documents.pm-visit', $sheet->handle($visit), "{$visit->visit_no}.pdf");
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }
}
