<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Document\Support\PdfImage;
use App\Modules\Labeling\Actions\QrSvg;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmReportVersion;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\PmNumber;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What the PM inspection sheet (documents.pm-sheet) prints, from one set of data: an approved
 * version's snapshot, or (no version yet) the work order as it is now, marked as a draft. The same
 * HTML is the PDF (Gotenberg) and the "view" page in the browser.
 *
 *   the whole work order  a summary page, then each piece of equipment on its own page (per_asset
 *                         templates) or a row of a table per template (grouped_table templates)
 *   one piece             by its item id in the data, with the same header
 *
 * The header has the company's current logo and name, the number as customers see it (PmNumber)
 * and the QR to the tracking page (APP_URL). The customer's copy leaves out Response Time (office
 * use). Photos are read from the items still there and shrunk for print.
 */
class PmReportDocument
{
    public function __construct(private TenantContext $context, private PmReportData $reportData, private PmTrackingUrl $trackingUrl, private QrSvg $qr) {}

    /**
     * @param  bool  $customerCopy  the customer's copy: without the office-use blocks
     * @param  bool  $screen  shown in the browser: the status badge on screen only
     * @return array<string, mixed> the view data of documents.pm-sheet
     */
    public function handle(PmVisit $visit, ?PmReportVersion $version, ?int $itemId = null, bool $customerCopy = false, bool $screen = false): array
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

        $trackUrl = $this->trackingUrl->handle($visit);
        // Blocks shown: those any template of the work order turns on (older templates: the signatures only).
        $blocks = collect($items)->flatMap(fn (array $item) => array_keys(array_filter($item['blocks'] ?? ['acknowledgement' => true])))->unique()->values()->all();
        if ($customerCopy) {
            $blocks = array_values(array_diff($blocks, ['response_time']));
        }

        return [
            'company' => $tenant?->name,
            'number' => PmNumber::shown($visit->visit_no, $visit->tenant_id),
            'round' => $report['visit']['round'] ?? null,
            'trackUrl' => $trackUrl,
            'qr' => $this->qr->handle($trackUrl),
            'blocks' => $blocks,
            'customerCopy' => $customerCopy,
            'screen' => $screen,
            'oneItem' => $itemId !== null,
            // Pieces of equipment in print order: per_asset ones each on a page, grouped_table ones
            // together in a table per template.
            'sections' => $this->sections($items),
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

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{layout: string, items: list<array<string, mixed>>}>
     */
    private function sections(array $items): array
    {
        $sections = [];
        $tables = [];
        foreach ($items as $item) {
            if (($item['layout'] ?? PmChecklist::LAYOUT_PER_ASSET) === PmChecklist::LAYOUT_GROUPED_TABLE) {
                $key = $item['template_code'] ?? json_encode(array_column($item['checks'], 'key'));
                if (! isset($tables[$key])) {
                    $tables[$key] = count($sections);
                    $sections[] = ['layout' => PmChecklist::LAYOUT_GROUPED_TABLE, 'items' => []];
                }
                $sections[$tables[$key]]['items'][] = $item;
            } else {
                $sections[] = ['layout' => PmChecklist::LAYOUT_PER_ASSET, 'items' => [$item]];
            }
        }

        return array_map(fn (array $section) => $section['layout'] === PmChecklist::LAYOUT_GROUPED_TABLE
            ? ['layout' => $section['layout'], 'items' => $this->withParts($section['items'])]
            : $section, $sections);
    }

    /**
     * The rows of a table with each part (parent_asset_id: a PC's monitor) right under its main
     * piece, marked as a part. Sheets kept from before parts were known keep their order.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function withParts(array $items): array
    {
        $ids = array_filter(array_map(fn (array $item) => $item['asset']['id'] ?? null, $items));
        $parts = [];
        foreach ($items as $item) {
            $parent = $item['asset']['parent_asset_id'] ?? null;
            if ($parent !== null && in_array($parent, $ids, true)) {
                $parts[$parent][] = ['part' => true] + $item;
            }
        }

        $rows = [];
        foreach ($items as $item) {
            $parent = $item['asset']['parent_asset_id'] ?? null;
            if ($parent !== null && in_array($parent, $ids, true)) {
                continue;
            }
            $rows[] = $item;
            array_push($rows, ...($parts[$item['asset']['id'] ?? 0] ?? []));
        }

        return $rows;
    }

    /** The footer of every page: the document, its version and "page x / y" (no Thai: no web fonts there). */
    public function footer(PmVisit $visit, ?PmReportVersion $version, ?string $assetCode = null): string
    {
        $label = e(collect([PmNumber::shown($visit->visit_no, $visit->tenant_id), $assetCode, $version ? 'v'.$version->version : 'DRAFT'])->filter()->implode(' · '));

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
