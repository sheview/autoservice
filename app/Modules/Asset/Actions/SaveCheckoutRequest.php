<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\PartsForCheckout;
use App\Modules\Platform\Support\ItemPurpose;
use App\Modules\Platform\Support\Modules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a request and its lines while it is a draft (new, or the requester's draft). Lines are
 * checked here: an asset must be in use or spare and have enough not held by other requests (a
 * single asset is one); a part must be active, and parts need a ticket (the line's, else the
 * request's). A part short of stock is allowed (the page warns): what is missing is backordered
 * when handed out. Each line keeps what it is for, for which customer and ticket, and the serial
 * or photo of the unit it replaces (photos live on the request; those no line keeps are removed).
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
     *     qty?: int|null, due_return_date?: string|null, note?: string|null, purchase_request_id?: int|null,
     *     purpose?: string|null, purpose_other?: string|null, customer_name?: string|null, ticket_id?: int|null,
     *     replaced_serial?: string|null, replaced_photo?: UploadedFile|null, replaced_photo_id?: int|null}>}  $data  validated
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
            if ($outsideRequester === null) {
                foreach ($lines as $index => $line) {
                    if ($line['item_type'] === CheckoutItem::TYPE_PART && $line['ticket_id'] === null) {
                        throw ValidationException::withMessages(["items.{$index}.ticket_id" => __('asset.requests.ticket_required')]);
                    }
                }
            }

            $userId = $data['borrower_user_id'] ?? null;
            $borrower = $userId ? User::query()->find($userId) : null;
            $fields = [
                'borrower_user_id' => $userId,
                'borrower_name' => $userId ? ($this->userNames->handle([$userId])[$userId] ?? '') : trim((string) ($data['borrower_name'] ?? '')),
                // No longer asked (each line names its customer): a draft keeps what it had.
                'borrower_department' => $data['borrower_department'] ?? $request?->borrower_department,
                'borrower_phone' => $data['borrower_phone'] ?? null,
                'branch_id' => $borrower?->branch_id ?? $actor?->branch_id,
                // The papers and the older pages show one ticket: the request's, else its first line's.
                'ticket_id' => $data['ticket_id'] ?? collect($lines)->pluck('ticket_id')->filter()->first(),
                // Likewise one project (the reports go by it): the request's, else its first line's.
                'contract_id' => $data['contract_id'] ?? collect($lines)->pluck('contract_id')->filter()->first(),
                'purpose' => $data['purpose'] ?? $request?->purpose,
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

            // The photo of the unit a line replaces: a new one, or the one the draft had.
            $had = $request->getMedia(CheckoutRequest::REPLACED_PHOTOS)->pluck('id')->all();
            foreach ($lines as $index => $line) {
                $photo = $data['items'][$index]['replaced_photo'] ?? null;
                if ($photo instanceof UploadedFile && $line['purpose'] !== null && ItemPurpose::needsEvidence($line['purpose'])) {
                    $line['replaced_photo_id'] = $request->addMedia($photo)->toMediaCollection(CheckoutRequest::REPLACED_PHOTOS)->id;
                } elseif (! in_array($line['replaced_photo_id'], $had, true)) {
                    $line['replaced_photo_id'] = null;
                }
                $request->items()->create($line);
            }
            $used = $request->items()->pluck('replaced_photo_id')->filter()->all();
            $request->getMedia(CheckoutRequest::REPLACED_PHOTOS)->reject(fn ($media) => in_array($media->id, $used))->each->delete();

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
            $purpose = $item['purpose'] ?? null;
            $replaces = ItemPurpose::needsEvidence($purpose);
            // What it is for, for whom and on which job (the request's ticket when the line has none).
            $about = [
                'purpose' => $purpose,
                'purpose_other' => $purpose === ItemPurpose::OTHER ? ($item['purpose_other'] ?? null) : null,
                'for_internal' => (bool) ($item['for_internal'] ?? false),
                'customer_name' => ($item['for_internal'] ?? false) ? null : ($item['customer_name'] ?? null),
                'contract_id' => ($item['contract_id'] ?? $data['contract_id'] ?? null) === null ? null : (int) ($item['contract_id'] ?? $data['contract_id']),
                'ticket_id' => ($item['ticket_id'] ?? $data['ticket_id'] ?? null) === null ? null : (int) ($item['ticket_id'] ?? $data['ticket_id']),
                'replaced_serial' => $replaces ? ($item['replaced_serial'] ?? null) : null,
                'replaced_photo_id' => $replaces && isset($item['replaced_photo_id']) ? (int) $item['replaced_photo_id'] : null,
            ];
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
                    ...$about,
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
                    ...$about,
                ];
            }
        }

        return $lines;
    }
}
