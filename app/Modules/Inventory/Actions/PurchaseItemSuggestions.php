<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Document\Support\PhotoSlots;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Money;
use Illuminate\Support\Facades\Gate;

/**
 * What the purchase request form suggests while the item name is typed: parts already in stock
 * (with their photo and cost) and items bought before (the latest request of each name, within
 * the user's purchase-requests.view). Picking one fills the name, unit, price and kind.
 */
class PurchaseItemSuggestions
{
    public const LIMIT = 6;

    /**
     * @return list<array{source: string, code: string|null, name: string, detail: string|null, unit: string|null,
     *     unit_price: string|null, item_kind: string|null, asset_category_id: int|null, description: string|null,
     *     links: list<string>, on_hand: int|null, photo: string|null}>
     */
    public function handle(User $user, string $search): array
    {
        if (mb_strlen($search) < 2) {
            return [];
        }

        return [...$this->parts($search), ...$this->purchases($user, $search)];
    }

    private function parts(string $search): array
    {
        if (! Gate::allows('viewAny', Part::class)) {
            return [];
        }

        return Part::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('part_number', 'like', "%{$search}%"))
            ->orderBy('name')->limit(self::LIMIT)->with('media')
            ->get(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'qty_on_hand', 'unit_cost'])
            ->map(fn (Part $part) => [
                'source' => 'part',
                'code' => $part->code,
                'name' => $part->name,
                'detail' => collect([$part->brand, $part->part_number])->filter()->implode(' · ') ?: null,
                'unit' => $part->unit,
                'unit_price' => Money::toBaht($part->unit_cost),
                'item_kind' => 'part',
                'asset_category_id' => null,
                'description' => null,
                'links' => [],
                'on_hand' => (int) $part->qty_on_hand,
                'photo' => PhotoSlots::thumbnail($part, fn (int $slot) => route('inventory.parts.photos.show', [$part, $slot])),
            ])
            ->all();
    }

    private function purchases(User $user, string $search): array
    {
        // The latest few first; one per name (the same item asked for again is shown once).
        return SearchPurchaseRequests::visibleTo(PurchaseRequest::query(), $user)
            ->where('item_name', 'like', "%{$search}%")
            ->orderByDesc('id')->limit(self::LIMIT * 5)
            ->get(['id', 'pr_no', 'item_name', 'description', 'unit', 'unit_price', 'item_kind', 'asset_category_id', 'links'])
            ->unique(fn (PurchaseRequest $pr) => mb_strtolower(trim($pr->item_name)))
            ->take(self::LIMIT)
            ->map(fn (PurchaseRequest $pr) => [
                'source' => 'purchase',
                'code' => $pr->pr_no,
                'name' => $pr->item_name,
                'detail' => $pr->description,
                'unit' => $pr->unit,
                'unit_price' => Money::toBaht($pr->unit_price),
                'item_kind' => $pr->item_kind,
                'asset_category_id' => $pr->asset_category_id,
                'description' => $pr->description,
                'links' => array_values($pr->links ?? []),
                'on_hand' => null,
                'photo' => null,
            ])
            ->values()
            ->all();
    }
}
