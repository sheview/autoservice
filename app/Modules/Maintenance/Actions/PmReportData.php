<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetDevices;
use App\Modules\Asset\Actions\CategoryNames;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Maintenance\Support\PmSettings;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketLabels;

/**
 * Everything a PM report says, as plain data: the snapshot frozen into a report version when the
 * work order is approved (CreatePmReportVersion), and the source of both PDFs (the consolidated
 * report and one report per piece of equipment). Dates are ISO strings. Photos are media ids
 * (printed while they exist); signatures are kept as PNG data themselves, since signing again
 * replaces the file.
 */
class PmReportData
{
    public function __construct(
        private ListCustomers $listCustomers,
        private ContractDetails $contractDetails,
        private AssetDetails $assetDetails,
        private AssetDevices $assetDevices,
        private CategoryNames $categoryNames,
        private UserNames $userNames,
        private TicketLabels $ticketLabels,
        private Modules $modules,
    ) {}

    /**
     * @return array{visit: array<string, mixed>, items: list<array<string, mixed>>, totals: array<string, int>,
     *     signatures: array<string, array<string, mixed>|null>, note: string|null}
     */
    public function handle(PmVisit $visit): array
    {
        $items = $visit->items()->with('media')->orderBy('id')->get();
        $assetIds = $items->pluck('asset_id')->all();
        $assets = $this->assetDetails->handle($assetIds);
        $devices = $this->assetDevices->handle($assetIds);
        $categories = $this->categoryNames->handle(withTrashed: true);
        $contract = $visit->contract_id ? ($this->contractDetails->handle([$visit->contract_id])[$visit->contract_id] ?? null) : null;
        $customer = collect($this->listCustomers->handle(withTrashed: true))->firstWhere('id', $visit->customer_id);
        $tickets = $this->modules->enabled('service') ? $this->ticketLabels->handle($items->pluck('ticket_id')->all()) : [];
        $names = $this->userNames->handle([
            $visit->assignee_id, $visit->submitted_by, $visit->reviewed_by, $visit->approved_by, ...$items->pluck('checked_by')->all(),
        ]);
        $iso = fn ($date) => $date?->toIso8601String();

        return [
            'visit' => [
                ...$visit->only(['visit_no', 'round', 'summary']),
                'title' => $visit->displayTitle(),
                'customer' => $customer ? ['name' => $customer['name'], 'code' => $customer['code']] : null,
                'contract_no' => $contract['contract_no'] ?? null,
                'contract_title' => $contract['title'] ?? null,
                'assignee' => $names[$visit->assignee_id] ?? null,
                'period_starts_on' => $visit->period_starts_on?->toDateString(),
                'due_on' => $visit->due_on?->toDateString(),
                'scheduled_on' => $visit->scheduled_on?->toDateString(),
                'started_at' => $iso($visit->started_at),
                'completed_at' => $iso($visit->completed_at),
                'submitted_by' => $names[$visit->submitted_by] ?? null,
                'reviewed_by' => $names[$visit->reviewed_by] ?? null,
                'reviewed_at' => $iso($visit->reviewed_at),
                'approved_by' => $names[$visit->approved_by] ?? null,
                'approved_at' => $iso($visit->approved_at),
            ],
            'items' => $items->map(function (PmVisitItem $item) use ($assets, $devices, $categories, $tickets, $names, $iso) {
                $asset = $assets[$item->asset_id] ?? null;
                $device = $devices[$item->asset_id] ?? [];
                $checklist = $item->checklist ?? [];
                $answers = $item->answers ?? [];

                return [
                    'id' => $item->id,
                    'asset' => $asset ? [
                        'asset_code' => $asset['asset_code'],
                        'name' => $asset['name'],
                        'category' => $categories[$asset['category_id']] ?? null,
                        ...collect($device)->only(['brand', 'model', 'serial_number', 'property_no', 'location', 'ip_address'])->all(),
                    ] : null,
                    'result' => $item->result,
                    'note' => $item->note,
                    // Each question with its answer, its note and how it came out, in checklist order.
                    'checks' => array_map(fn (array $field) => [
                        ...collect($field)->only(['key', 'label', 'type', 'section', 'unit', 'min', 'max'])->all(),
                        'answer' => $answers[$field['key']] ?? null,
                        'remark' => $item->remarks[$field['key']] ?? null,
                        'verdict' => ChecklistItems::verdict($field, $answers[$field['key']] ?? null),
                    ], $checklist),
                    'failures' => ChecklistItems::failures($checklist, $answers),
                    'photos' => $item->getMedia(PmVisitItem::PHOTOS)->map(fn ($media) => [
                        'id' => $media->id,
                        'question' => $media->getCustomProperty('question'),
                        'caption' => $media->getCustomProperty('caption'),
                    ])->values()->all(),
                    'ticket_no' => $tickets[$item->ticket_id]['ticket_no'] ?? null,
                    'checked_by' => $names[$item->checked_by] ?? null,
                    'checked_at' => $iso($item->checked_at),
                ];
            })->all(),
            'totals' => [
                'total' => $items->count(),
                ...collect([PmVisitItem::RESULT_OK, PmVisitItem::RESULT_ISSUE, PmVisitItem::RESULT_SKIPPED, PmVisitItem::RESULT_PENDING])
                    ->mapWithKeys(fn (string $result) => [$result => $items->where('result', $result)->count()])->all(),
            ],
            'signatures' => [
                'technician' => $this->signature($visit, PmVisit::TECHNICIAN_SIGNATURE),
                'customer' => $this->signature($visit, PmVisit::CUSTOMER_SIGNATURE),
            ],
            'note' => PmSettings::of()->reportNote,
        ];
    }

    /** @return array{image: string, signer: string|null, position: string|null, signed_at: string|null}|null */
    private function signature(PmVisit $visit, string $collection): ?array
    {
        $media = $visit->getFirstMedia($collection);

        return $media ? [
            'image' => 'data:image/png;base64,'.base64_encode(stream_get_contents($media->stream())),
            'signer' => $media->getCustomProperty('signer'),
            'position' => $media->getCustomProperty('position'),
            'signed_at' => $media->getCustomProperty('signed_at'),
        ] : null;
    }
}
