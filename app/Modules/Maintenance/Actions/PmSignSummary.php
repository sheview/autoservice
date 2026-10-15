<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Maintenance\Models\PmSignature;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Maintenance\Support\PmNumber;

/**
 * What a signer signs for: the work order, how many pieces of equipment came out how, what failed
 * (by equipment), and who signed already. For the technician's sign page and the customer's link.
 */
class PmSignSummary
{
    public function __construct(private ListCustomers $listCustomers, private AssetDetails $assetDetails) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(PmVisit $visit): array
    {
        $items = $visit->items()->orderBy('id')->get();
        $assets = $this->assetDetails->handle($items->where('result', PmVisitItem::RESULT_FAILED)->pluck('asset_id')->all());
        $customer = collect($this->listCustomers->handle(withTrashed: true))->firstWhere('id', $visit->customer_id);

        return [
            'number' => PmNumber::shown($visit->visit_no, $visit->tenant_id),
            'title' => $visit->displayTitle(),
            'customer' => $customer['name'] ?? null,
            'completed_at' => $visit->completed_at?->toIso8601String(),
            'summary' => $visit->summary,
            'totals' => collect([PmVisitItem::RESULT_PASSED, PmVisitItem::RESULT_FAILED, PmVisitItem::RESULT_NOT_INSPECTED, PmVisitItem::RESULT_PENDING])
                ->mapWithKeys(fn (string $result) => [$result => $items->where('result', $result)->count()])->all() + ['total' => $items->count()],
            'failures' => $items->where('result', PmVisitItem::RESULT_FAILED)->map(fn (PmVisitItem $item) => [
                'asset' => isset($assets[$item->asset_id]) ? $assets[$item->asset_id]['asset_code'].' '.$assets[$item->asset_id]['name'] : null,
                'failures' => ChecklistItems::failures($item->checklist ?? [], $item->answers ?? []),
                'note' => $item->note,
            ])->values()->all(),
            'signatures' => $visit->signatures()->orderBy('id')->get()->map(fn (PmSignature $signature) => [
                'role' => $signature->role,
                'name' => $signature->name,
                'signed_at' => $signature->signed_at->toIso8601String(),
            ])->all(),
            'locked_at' => $visit->locked_at?->toIso8601String(),
        ];
    }
}
