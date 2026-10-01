<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Asset\Support\CheckoutAlert;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Actions\SendAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asks to issue or lend an asset (or some of an asset bought by the lot) to someone. The request
 * waits for an approver (DecideCheckout). Only an asset that is in use or spare, with enough of its
 * quantity not already asked for or out, can be asked for.
 */
class RequestCheckout
{
    /** Asset statuses that can be handed out. */
    public const AVAILABLE_STATUSES = [Asset::STATUS_IN_USE, Asset::STATUS_SPARE];

    public function __construct(
        private GenerateCheckoutNumber $generateNumber,
        private UserNames $userNames,
    ) {}

    /**
     * @param  array{type: string, borrower_user_id?: int|null, borrower_name?: string|null, borrower_department?: string|null,
     *     borrower_phone?: string|null, purpose?: string|null, due_on?: string|null, quantity?: int|null, contract_id?: int|null}  $data  validated
     */
    public function handle(Asset $asset, array $data, User $actor): AssetCheckout
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            // One request at a time per asset, even when two people press at once.
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);

            $quantity = max(1, (int) ($data['quantity'] ?? 1));
            $left = self::availableQuantity($asset);
            if (! in_array($asset->status, self::AVAILABLE_STATUSES, true) || $left < 1) {
                throw ValidationException::withMessages(['type' => __('asset.checkouts.not_available')]);
            }
            if ($quantity > $left) {
                throw ValidationException::withMessages(['quantity' => __('asset.checkouts.not_enough', ['available' => $left, 'unit' => $asset->unit ?? ''])]);
            }

            $userId = $data['borrower_user_id'] ?? null;

            $checkout = AssetCheckout::create([
                'asset_id' => $asset->id,
                'contract_id' => $data['contract_id'] ?? null,
                'checkout_no' => $this->generateNumber->handle(),
                'type' => $data['type'],
                'quantity' => $quantity,
                'status' => AssetCheckout::STATUS_PENDING,
                'borrower_user_id' => $userId,
                'borrower_name' => $userId ? ($this->userNames->handle([$userId])[$userId] ?? $data['borrower_name']) : trim($data['borrower_name']),
                'borrower_department' => $data['borrower_department'] ?? null,
                'borrower_phone' => $data['borrower_phone'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'due_on' => $data['type'] === AssetCheckout::TYPE_LOAN ? $data['due_on'] : null,
                'requested_by' => $actor->id,
                'requested_by_name' => $actor->name,
            ]);

            activity()->performedOn($asset)->causedBy($actor)->event('checkout_requested')
                ->withProperties(['checkout_no' => $checkout->checkout_no, 'type' => $checkout->type, 'borrower' => $checkout->borrower_name])
                ->log('ขอเบิก/ยืม');

            app(SendAlert::class)->handle('checkout_requested', CheckoutAlert::replace($checkout, $asset, $actor), route('asset.assets.show', $asset));

            return $checkout;
        });
    }

    public static function available(Asset $asset): bool
    {
        return in_array($asset->status, self::AVAILABLE_STATUSES, true) && self::availableQuantity($asset) > 0;
    }

    /**
     * How many of the asset can still be asked for: its quantity minus what open forms hold.
     */
    public static function availableQuantity(Asset $asset): int
    {
        $held = app(CheckedOutQuantities::class)->handle([$asset->id])[$asset->id] ?? 0;

        return max(0, (int) $asset->quantity - $held);
    }
}
