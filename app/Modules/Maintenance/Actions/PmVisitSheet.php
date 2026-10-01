<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Contract\Actions\ContractDetails;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * What the PM report of a round shows (the PDF given to the customer): the round, every asset
 * checked with its checklist answers, and room for both signatures.
 */
class PmVisitSheet
{
    public function __construct(
        private TenantContext $context,
        private ListCustomers $listCustomers,
        private AssetDetails $assetDetails,
        private ContractDetails $contractDetails,
        private UserNames $userNames,
    ) {}

    /**
     * @return array{company: string|null, visit: array<string, mixed>, items: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function handle(PmVisit $visit): array
    {
        $items = $visit->items()->orderBy('id')->get();
        $assets = $this->assetDetails->handle($items->pluck('asset_id')->all());
        $contract = $this->contractDetails->handle([$visit->contract_id])[$visit->contract_id] ?? null;
        $names = $this->userNames->handle([$visit->assignee_id]);

        return [
            'company' => $this->context->tenant()?->name,
            'visit' => [
                ...$visit->only(['visit_no', 'round', 'status', 'summary']),
                'plan' => $visit->plan?->title,
                'customer' => collect($this->listCustomers->handle(withTrashed: true))->firstWhere('id', $visit->customer_id)['name'] ?? null,
                'contract_no' => $contract['contract_no'] ?? null,
                'assignee' => $names[$visit->assignee_id] ?? null,
                'period_starts_on' => $visit->period_starts_on,
                'due_on' => $visit->due_on,
                'scheduled_on' => $visit->scheduled_on,
                'started_at' => $visit->started_at,
                'completed_at' => $visit->completed_at,
            ],
            'items' => $items->map(fn (PmVisitItem $item) => [
                'asset' => isset($assets[$item->asset_id]) ? collect($assets[$item->asset_id])->only(['asset_code', 'name'])->all() : null,
                'result' => $item->result,
                'note' => $item->note,
                // Each checklist question with its answer, in the order of the checklist.
                'checks' => collect($item->checklist ?? [])->map(fn (array $field) => [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'answer' => $item->answers[$field['key']] ?? null,
                ])->all(),
            ])->all(),
            'totals' => [
                'total' => $items->count(),
                ...collect([PmVisitItem::RESULT_OK, PmVisitItem::RESULT_ISSUE, PmVisitItem::RESULT_SKIPPED, PmVisitItem::RESULT_PENDING])
                    ->mapWithKeys(fn (string $result) => [$result => $items->where('result', $result)->count()])->all(),
            ],
        ];
    }
}
