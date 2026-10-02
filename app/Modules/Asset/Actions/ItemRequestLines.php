<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\RequestRow;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The request lines of one asset or one part, for the panel on its page (ItemRequestsPanel):
 * the lines still in progress (to decide, to hand out, or lent and not back) first, then the
 * last finished ones. Only lines of requests the user reaches (view / approve / fulfill /
 * return, each in its scope); a draft only for its writer. Null when the user has nothing to
 * do with requests (no asset-checkouts permission, or a customer account).
 */
class ItemRequestLines
{
    public const HISTORY = 10;

    public const ABILITIES = ['view', 'request', 'create', 'approve', 'fulfill', 'return'];

    /**
     * @param  string  $itemType  CheckoutItem::TYPE_ASSET | TYPE_PART
     * @return array{lines: list<array<string, mixed>>, can: array{create: bool}}|null
     */
    public function handle(User $user, string $itemType, int $itemId): ?array
    {
        if ($user->customer_id !== null || ! collect(self::ABILITIES)->contains(fn (string $ability) => $user->can("asset-checkouts.{$ability}"))) {
            return null;
        }

        $open = fn (Builder $q) => $q->whereNotIn('checkout_items.status', CheckoutItem::FINISHED)
            ->orWhere(fn ($q) => $q->where('checkout_items.checkout_type', CheckoutItem::LOAN)
                ->whereColumn('checkout_items.qty_fulfilled', '>', 'checkout_items.qty_returned'));

        $lines = $this->lines($user, $itemType, $itemId)->where($open)->oldest('checkout_items.id')->get()
            ->concat($this->lines($user, $itemType, $itemId)->whereNot($open)->latest('checkout_items.id')->limit(self::HISTORY)->get());

        return [
            'lines' => $lines->map(fn (CheckoutItem $item) => self::row($item))->values()->all(),
            'can' => ['create' => $user->can('create', CheckoutRequest::class)],
        ];
    }

    /**
     * A line with its request, as the panel and the summaries show it.
     *
     * @return array<string, mixed>
     */
    public static function row(CheckoutItem $item): array
    {
        $request = $item->request;

        return [
            ...RequestRow::item($item),
            'request' => [
                ...$request->only(['ulid', 'request_no', 'status', 'borrower_user_id', 'borrower_name', 'borrower_department', 'requester_name', 'contract_id']),
                'needed_by' => $request->needed_by?->toDateString(),
                'requested_at' => ($request->submitted_at ?? $request->created_at)?->toIso8601String(),
            ],
        ];
    }

    /** @return Builder<CheckoutItem> */
    private function lines(User $user, string $itemType, int $itemId): Builder
    {
        return CheckoutItem::query()
            ->select('checkout_items.*')
            ->join('checkout_requests', 'checkout_requests.id', '=', 'checkout_items.request_id')
            ->whereNull('checkout_requests.deleted_at')
            ->where('checkout_items.item_type', $itemType)
            ->where($itemType === CheckoutItem::TYPE_PART ? 'checkout_items.part_id' : 'checkout_items.asset_id', $itemId)
            ->where(fn ($q) => self::reachable($q, $user))
            // A draft is only its writer's.
            ->where(fn ($q) => $q->where('checkout_requests.status', '!=', CheckoutRequest::STATUS_DRAFT)
                ->orWhere('checkout_requests.requester_id', $user->id))
            ->with(['request', 'asset:id,ulid']);
    }

    /**
     * Lines of the requests the user reaches with any of view / approve / fulfill / return (the
     * policy's "view"), each within its own scope.
     *
     * @param  Builder<CheckoutItem>  $query
     */
    public static function reachable(Builder $query, User $user): void
    {
        foreach (['view', 'approve', 'fulfill', 'return'] as $ability) {
            $query->orWhereIn('checkout_items.request_id',
                SearchCheckoutRequests::visibleTo(CheckoutRequest::query(), $user, "asset-checkouts.{$ability}")->select('checkout_requests.id'));
        }
    }
}
