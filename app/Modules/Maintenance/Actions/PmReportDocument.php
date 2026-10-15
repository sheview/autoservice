<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Document\Support\PdfImage;
use App\Modules\Maintenance\Models\PmReportVersion;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the two PM report PDFs print, from one set of data: an approved version's snapshot, or
 * (no version yet) the work order as it is now, marked as a draft.
 *
 *   consolidated  the whole work order: every piece of equipment, the summary, the signatures
 *   equipment     one piece of equipment (by its item id in the data) with the same header
 *
 * Photos are read from the items still there and shrunk for print; a photo removed since the
 * version was approved is left out. The company's current logo and name head the report.
 */
class PmReportDocument
{
    public function __construct(private TenantContext $context, private PmReportData $reportData) {}

    /**
     * @return array<string, mixed> the view data of documents.pm-report / documents.pm-equipment-report
     */
    public function handle(PmVisit $visit, ?PmReportVersion $version, ?int $itemId = null): array
    {
        $report = $version?->snapshot ?? $this->reportData->handle($visit);
        $items = $itemId === null
            ? $report['items']
            : array_values(array_filter($report['items'], fn (array $item) => $item['id'] === $itemId));
        if ($itemId !== null && $items === []) {
            throw new NotFoundHttpException;
        }

        $tenant = $this->context->tenant();
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);

        return [
            'company' => $tenant?->name,
            // As uploaded (a JPEG copy would lose a transparent background).
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'report' => $report,
            'items' => $items,
            'photos' => $this->photos($visit, $items),
            'version' => $version?->version,
            'approvedAt' => $version?->created_at,
            'draft' => $version === null,
        ];
    }

    /** The footer of every page: the document, its version and "page x / y" (no Thai: no web fonts there). */
    public function footer(PmVisit $visit, ?PmReportVersion $version, ?string $assetCode = null): string
    {
        $label = e(collect([$visit->visit_no, $assetCode, $version ? 'v'.$version->version : 'DRAFT'])->filter()->implode(' · '));

        return '<!doctype html><html><head><style>'
            .'body{margin:0 0.5in;font-family:Arial,Helvetica,sans-serif;font-size:7.5pt;color:#666;'
            .'display:flex;justify-content:space-between;width:100%;-webkit-print-color-adjust:exact}'
            .'</style></head><body><span>'.$label.'</span>'
            .'<span><span class="pageNumber"></span> / <span class="totalPages"></span></span></body></html>';
    }

    public function fileName(PmVisit $visit, ?PmReportVersion $version, ?string $assetCode = null): string
    {
        $name = collect([$visit->visit_no, $assetCode, $version ? 'v'.$version->version : 'draft'])->filter()->implode('_');

        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name).'.pdf';
    }

    /**
     * Photos of the printed items still on the work order, shrunk, by media id.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function photos(PmVisit $visit, array $items): array
    {
        $wanted = collect($items)->flatMap(fn (array $item) => array_column($item['photos'] ?? [], 'id'))->all();
        if ($wanted === []) {
            return [];
        }

        $photos = [];
        $visit->items()->whereIn('id', array_column($items, 'id'))->with('media')->get()
            ->flatMap(fn (PmVisitItem $item) => $item->getMedia(PmVisitItem::PHOTOS))
            ->filter(fn ($media) => in_array($media->id, $wanted, true))
            ->each(function ($media) use (&$photos) {
                if ($image = PdfImage::fromMedia($media)) {
                    $photos[$media->id] = $image;
                }
            });

        return $photos;
    }
}
