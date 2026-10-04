<?php

namespace App\Modules\Labeling\Actions;

use App\Modules\Contract\Actions\ContractsForAsset;
use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Document\Support\ThaiDate;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicUrl;

/**
 * What is printed on each label, from the asset rows (AssetSummaries) the user may see. A detailed
 * label also carries the contract that covers the device (or the next one to start), with the
 * Buddhist-year period the customer reads on the sticker.
 */
class BuildLabels
{
    public function __construct(
        private QrSvg $qr,
        private CustomerLabelNames $customerNames,
        private ContractsForAsset $contractsForAsset,
        private Modules $modules,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $assets  rows of AssetSummaries
     * @return list<array<string, mixed>>
     */
    public function handle(array $assets, bool $detailed): array
    {
        $contractOn = $this->modules->enabled('contract');
        // Short names where a customer has one: the label has little room.
        $customers = $contractOn ? collect($this->customerNames->handle()) : collect();

        return array_map(fn (array $asset) => [
            ...collect($asset)->only(['ulid', 'asset_code', 'name', 'category', 'serial_number', 'property_no', 'brand', 'model'])->all(),
            'customer' => $customers[$asset['customer_id']] ?? null,
            'province' => $asset['branch_province'] ?? null,
            'contract' => $detailed && $contractOn ? $this->contract($asset['id']) : null,
            'qr' => $this->qr->handle(PublicUrl::route('labeling.scan', $asset['ulid'])),
        ], $assets);
    }

    /**
     * @return array{title: string, contract_no: string, period: string}|null
     */
    private function contract(int $assetId): ?array
    {
        $contracts = collect($this->contractsForAsset->handle($assetId));
        $contract = $contracts->firstWhere('covering', true) ?? $contracts->firstWhere('phase', 'upcoming');

        return $contract === null ? null : [
            'title' => $contract['title'],
            'contract_no' => $contract['contract_no'],
            'period' => __('ui.labels.contract_period', [
                'from' => ThaiDate::long($contract['starts_on']),
                'to' => ThaiDate::long($contract['ends_on']),
            ]),
        ];
    }
}
