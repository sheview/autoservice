<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\PartsForCheckout;
use App\Modules\Platform\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a request and its lines while it is a draft (new, or the requester's draft). Lines are
 * checked here: an asset must be in use or spare and have enough not held by other requests (a
 * single asset is one); a part must be active, and parts need the request's ticket. A part short
 * of stock is allowed (the page warns): what is missing is backordered when handed out.
 * Sending it for approval is SubmitCheckoutRequest.
 */
class SaveCheckoutRequest
{
    public function __construct(
        private GenerateRequestNumber $generateNumber,
        private UserNames $userNames,
        private AssetHeldQuantities $held,
        private PartsForCheckout $parts,
        private Modules $modules,
    ) {}

    /**
     * @param  array{borrower_user_id?: int|null, borrower_name?: string|null, borrower_department?: string|null,
     *     borrower_phone?: string|null, ticket_id?: int|null, contract_id?: int|null, purpose?: string|null, needed_by?: string|null,
     *     items: list<array{item_type: string, asset_id?: int|null, part_id?: int|null, checkout_type?: string|null,
     *     qty?: int|null, due_return_date?: string|null, note?: string|null, purchase_request_id?: int|null}>}  $data  validated
     * @param  string|null  $outsideRequester  a person of another company asking through a share (no $actor here,
     *                                         and no ticket of this company: the job is theirs)
     */
    public function handle(?CheckoutRequest $request, array $data, ?User $actor, ?string $outsideRequester = null): CheckoutRequest
    {
        if ($request !== null && $request->status !== CheckoutRequest::STATUS_DRAFT) {
            throw ValidationException::withMessages(['request' => __('asset.requests.not_draft')]);
        }

        return DB::transaction(function () use ($request, $data, $actor, $outsideRequester) {
            $lines = $this->lines($data, $request);
            if ($outsideRequester === null && collect($lines)->contains('item_type', CheckoutItem::TYPE_PART) && empty($data['ticket_id'])) {
                throw ValidationException::withMessages(['ticket_id' => __('asset.requests.ticket_required')]);
            }

            $userId = $data['borrower_user_id'] ?? null;
            $borrower = $userId ? User::query()->find($userId) : null;
            $fields = [
                'borrower_user_id' => $userId,
                'borrower_name' => $userId ? ($this->userNames->handle([$userId])[$userId] ?? '') : trim((string) ($data['borrower_name'] ?? '')),
                'borrower_department' => $data['borrower_department'] ?? null,
                'borrower_phone' => $data['borrower_phone'] ?? null,
                'branch_id' => $borrower?->branch_id ?? $actor?->branch_id,
                'ticket_id' => $data['ticket_id'] ?? null,
                'contract_id' => $data['contract_id'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'needed_by' => $data['needed_by'] ?? null,
            ];

            if ($request === null) {
                $request = CheckoutRequest::create([
                    ...$fields,
                    'request_no' => $this->generateNumber->handle(),
                    'status' => CheckoutRequest::STATUS_DRAFT,
                    'requester_id' => $actor?->id,
                    'requester_name' => $outsideRequester ?? $actor?->name,
                ]);
            } else {
                $request->update($fields);
                $request->items()->delete();
            }

            foreach ($lines as $line) {
                $request->items()->create($line);
            }

            return $request->load('items');
        });
    }

    /**
     * The lines as they are stored, after the checks.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(array $data, ?CheckoutRequest $request): array
    {
        $items = $data['items'] ?? [];
        if ($items === []) {
            throw ValidationException::withMessages(['items' => __('asset.requests.no_items')]);
        }

        $assetIds = collect($items)->where('item_type', CheckoutItem::TYPE_ASSET)->pluck('asset_id')->filter()->map(fn ($id) => (int) $id)->all();
        $partIds = collect($items)->where('item_type', CheckoutItem::TYPE_PART)->pluck('part_id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($partIds !== [] && ! $this->modules->enabled('inventory')) {
            throw ValidationException::withMessages(['items' => __('asset.requests.no_parts')]);
        }

        $assets = Asset::query()->whereKey($assetIds)->get()->keyBy('id');
        $held = $this->held->handle($assetIds);
        $parts = $partIds === [] ? [] : $this->parts->handle($partIds);
        $askedFor = [];
        $lines = [];

        foreach ($items as $index => $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $type = ($item['checkout_type'] ?? CheckoutItem::ISSUE) === CheckoutItem::LOAN ? CheckoutItem::LOAN : CheckoutItem::ISSUE;
            $error = fn (string $key, array $replace = []) => throw ValidationException::withMessages(["items.{$index}" => __("asset.requests.{$key}", $replace)]);

            if ($item['item_type'] === CheckoutItem::TYPE_ASSET) {
                $asset = $assets->get((int) ($item['asset_id'] ?? 0));
                if ($asset === null || ! in_array($asset->status, [Asset::STATUS_IN_USE, Asset::STATUS_SPARE], true)) {
                    $error('asset_not_available');
                }
                if ((int) $asset->quantity <= 1) {
                    $qty = 1; // a single asset
                }
                $askedFor[$asset->id] = ($askedFor[$asset->id] ?? 0) + $qty;
                $left = (int) $asset->quantity - ($held[$asset->id] ?? 0);
                if ($askedFor[$asset->id] > $left) {
                    $error('asset_not_enough', ['name' => $asset->name, 'available' => max(0, $left), 'unit' => $asset->unit ?? '']);
                }
                if ($type === CheckoutItem::LOAN && empty($item['due_return_date'])) {
                    $error('due_required');
                }

                $lines[] = [
                    'item_type' => CheckoutItem::TYPE_ASSET, 'asset_id' => $asset->id, 'part_id' => null,
                    'item_code' => $asset->asset_code, 'item_name' => $asset->name, 'unit' => $asset->unit,
                    'checkout_type' => $type, 'qty_requested' => $qty, 'status' => CheckoutItem::STATUS_PENDING,
                    'due_return_date' => $type === CheckoutItem::LOAN ? $item['due_return_date'] : null,
                    'note' => $item['note'] ?? null,
                    'purchase_request_id' => $item['purchase_request_id'] ?? null,
                ];
            } else {
                $part = $parts[(int) ($item['part_id'] ?? 0)] ?? null;
                if ($part === null || ! $part['is_active']) {
                    $error('part_not_available');
                }

                // Parts are used up: always an issue.
                $lines[] = [
                    'item_type' => CheckoutItem::TYPE_PART, 'asset_id' => null, 'part_id' => $part['id'],
                    'item_code' => $part['code'], 'item_name' => $part['name'], 'unit' => $part['unit'],
                    'checkout_type' => CheckoutItem::ISSUE, 'qty_requested' => $qty, 'status' => CheckoutItem::STATUS_PENDING,
                    'due_return_date' => null, 'note' => $item['note'] ?? null,
                    'purchase_request_id' => $item['purchase_request_id'] ?? null,
                ];
            }
        }

        return $lines;
    }
}
