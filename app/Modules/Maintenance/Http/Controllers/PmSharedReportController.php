<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Maintenance\Actions\PmReportDocument;
use App\Modules\Maintenance\Actions\PmReportShareByToken;
use App\Modules\Maintenance\Models\PmVisitEvent;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicLookupGuard;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A customer's PM report link, without signing in: /pm-report/{token} on the company's own host,
 * or /t/{company code}/pm-report/{token} on the shared host. The token is looked for in that
 * company only (PmReportShareByToken), so a link never opens another company's report; anything
 * wrong reads the same "not found" and counts towards the lookup guard. The page previews the
 * published version; its PDFs (whole report, or one piece of equipment of that version) open or
 * download when the link allows it.
 */
class PmSharedReportController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private PmReportShareByToken $shareByToken,
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

    public function pdfOnHost(Request $request, PublicTenant $publicTenant, string $token, ?int $item = null): HttpResponse
    {
        return $this->pdf($request, $publicTenant->resolve(), $token, $item);
    }

    public function pdfOnPath(Request $request, string $code, string $token, ?int $item = null): HttpResponse
    {
        return $this->pdf($request, CompanyCodes::tenant($code), $token, $item);
    }

    private function show(?Tenant $tenant, string $token): Response
    {
        $found = $this->open($tenant, $token, PmVisitEvent::TYPE_SHARE_VIEWED);
        if ($found === null) {
            return Inertia::render('Maintenance/SharedReport', ['company' => null, 'report' => null]);
        }

        ['share' => $share, 'version' => $version] = $found;
        $report = $version->snapshot;
        $base = request()->url();

        return Inertia::render('Maintenance/SharedReport', [
            'company' => $tenant->name,
            'report' => [
                'visit' => collect($report['visit'])->only(['visit_no', 'title', 'round', 'summary', 'assignee', 'completed_at', 'approved_at', 'contract_no'])
                    ->put('customer', $report['visit']['customer']['name'] ?? null)->all(),
                'version' => $version->version,
                'totals' => $report['totals'],
                'items' => array_map(fn (array $item) => [
                    'id' => $item['id'],
                    'asset_code' => $item['asset']['asset_code'] ?? null,
                    'name' => $item['asset']['name'] ?? null,
                    'category' => $item['asset']['category'] ?? null,
                    'result' => $item['result'],
                    'failures' => $item['failures'],
                    'note' => $item['note'],
                    'pdf' => "{$base}/equipment/{$item['id']}/pdf",
                ], $report['items']),
                'pdf' => "{$base}/pdf",
                'allow_download' => $share->allow_download,
                'expires_at' => $share->expires_at->toIso8601String(),
            ],
        ]);
    }

    private function pdf(Request $request, ?Tenant $tenant, string $token, ?int $itemId): HttpResponse
    {
        $download = $request->boolean('download');
        $found = $this->open($tenant, $token, $download ? PmVisitEvent::TYPE_SHARE_DOWNLOADED : PmVisitEvent::TYPE_SHARE_VIEWED);
        if ($found === null || ($download && ! $found['share']->allow_download)) {
            throw new NotFoundHttpException;
        }

        ['visit' => $visit, 'version' => $version] = $found;

        return $this->context->run($tenant, function () use ($visit, $version, $itemId, $download) {
            $data = $this->document->handle($visit, $version, $itemId);
            $assetCode = $itemId !== null ? ($data['items'][0]['asset']['asset_code'] ?? null) : null;

            try {
                return $this->renderPdf->handle(
                    $itemId === null ? 'documents.pm-report' : 'documents.pm-equipment-report',
                    $data,
                    $this->document->fileName($visit, $version, $assetCode),
                    $this->document->footer($visit, $version, $assetCode),
                    $download,
                );
            } catch (PdfUnavailable) {
                abort(503, __('document.unavailable'));
            }
        });
    }

    /**
     * @return array<string, mixed>|null see PmReportShareByToken
     */
    private function open(?Tenant $tenant, string $token, string $what): ?array
    {
        $usable = $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('maintenance', $tenant);
        $found = $usable ? $this->context->run($tenant, fn () => $this->shareByToken->handle($token, request()->ip(), $what)) : null;
        if ($found === null) {
            PublicLookupGuard::missed(request()->ip(), $usable ? $tenant : null, 'pm-report-link');
        }

        return $found;
    }
}
