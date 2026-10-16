<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Maintenance\Actions\PmReportDocument;
use App\Modules\Maintenance\Actions\PmRoundReport;
use App\Modules\Maintenance\Actions\PmRoundShareByToken;
use App\Modules\Maintenance\Models\PmRoundShare;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicLookupGuard;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A customer's round report link, without signing in: /pm-round/{token} on the company's own
 * host, or /t/{company code}/pm-round/{token} on the shared host. The token is looked for in that
 * company only (PmRoundShareByToken); anything wrong reads the same "not found" and counts
 * towards the lookup guard. The page lists the round's approved work orders as they stand now;
 * the whole round, or one work order of it, opens as a PDF, prints from the browser, or downloads
 * when the link allows it.
 */
class PmSharedRoundController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private PmRoundShareByToken $shareByToken,
        private PmRoundReport $report,
        private PmReportDocument $document,
        private RenderPdf $renderPdf,
        private Modules $modules,
    ) {}

    public function showOnHost(PublicTenant $publicTenant, string $token): Response
    {
        return $this->show($publicTenant->resolve(), $token);
    }

    public function showOnPath(string $code, string $token): Response
    {
        return $this->show(CompanyCodes::tenant($code), $token);
    }

    public function pdfOnHost(Request $request, PublicTenant $publicTenant, string $token, ?string $visit = null): HttpResponse
    {
        return $this->pdf($request, $publicTenant->resolve(), $token, $visit);
    }

    public function pdfOnPath(Request $request, string $code, string $token, ?string $visit = null): HttpResponse
    {
        return $this->pdf($request, CompanyCodes::tenant($code), $token, $visit);
    }

    private function show(?Tenant $tenant, string $token): Response
    {
        $share = $this->open($tenant, $token);
        if ($share === null) {
            return Inertia::render('Maintenance/SharedRound', ['company' => null, 'report' => null]);
        }

        $base = request()->url();
        $cover = $this->context->run($tenant, function () use ($share) {
            $visits = $this->report->visits($share->filters());

            return $visits->isEmpty() ? null : $this->report->summary($share->filters(), $visits)['cover'];
        });

        return Inertia::render('Maintenance/SharedRound', [
            'company' => $tenant->name,
            'report' => [
                'customer' => $cover['customer'] ?? null,
                'from' => $share->from_on->toDateString(),
                'to' => $share->to_on->toDateString(),
                'visits' => array_map(fn (array $row) => [
                    ...collect($row)->only(['number', 'title', 'date', 'total', 'passed', 'failed', 'not_inspected', 'signed'])->all(),
                    'completed_at' => $row['completed_at']?->toIso8601String(),
                    'pdf' => "{$base}/visits/{$row['ulid']}/pdf",
                ], $cover['rows'] ?? []),
                'pdf' => "{$base}/pdf",
                'allow_download' => $share->allow_download,
                'expires_at' => $share->expires_at->toIso8601String(),
            ],
        ]);
    }

    private function pdf(Request $request, ?Tenant $tenant, string $token, ?string $visitUlid): HttpResponse
    {
        $download = $request->boolean('download');
        $share = $this->open($tenant, $token);
        if ($share === null || ($download && ! $share->allow_download)) {
            throw new NotFoundHttpException;
        }
        $print = ! $download && $request->boolean('print');

        return $this->context->run($tenant, fn () => $visitUlid === null
            ? $this->round($share, $download, $print)
            : $this->sheet($share, $visitUlid, $download, $print));
    }

    /** The whole round: the cover, then each work order's sheet. */
    private function round(PmRoundShare $share, bool $download, bool $print): HttpResponse
    {
        try {
            if ($print) {
                $printable = $this->report->printable($share->filters());

                return response()->view('documents.pm-round-print', [
                    'cover' => $printable['cover'], 'sheets' => $printable['sheets'], 'forBrowser' => true,
                ]);
            }
            $made = $this->report->handle($share->filters());
        } catch (ValidationException) {
            throw new NotFoundHttpException;
        } catch (PdfUnavailable) {
            abort(503, __('document.unavailable'));
        }

        return response($made['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$made['file'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** One work order of the round (only one the round shows). */
    private function sheet(PmRoundShare $share, string $visitUlid, bool $download, bool $print): HttpResponse
    {
        $visit = $this->report->visits($share->filters())->first(fn (PmVisit $visit) => $visit->ulid === $visitUlid);
        if ($visit === null) {
            throw new NotFoundHttpException;
        }

        $version = $this->report->version($visit);
        $data = $this->document->handle($visit, $version, null, customerCopy: true);
        // Printed from the browser: the same sheet, without the PDF service.
        if ($print) {
            return response()->view('documents.pm-sheet', [...$data, 'forBrowser' => true]);
        }

        try {
            return $this->renderPdf->handle(
                'documents.pm-sheet',
                $data,
                $this->document->fileName($visit, $version),
                $this->document->footer($visit, $version),
                $download,
            );
        } catch (PdfUnavailable) {
            abort(503, __('document.unavailable'));
        }
    }

    private function open(?Tenant $tenant, string $token): ?PmRoundShare
    {
        $usable = $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('maintenance', $tenant);
        $share = $usable ? $this->context->run($tenant, fn () => $this->shareByToken->handle($token)) : null;
        if ($share === null) {
            PublicLookupGuard::missed(request()->ip(), $usable ? $tenant : null, 'pm-round-link');
        }

        return $share;
    }
}
