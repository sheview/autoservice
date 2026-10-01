<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asks to issue or lend an asset to someone. The request waits for an approver (DecideCheckout).
 * Only an asset that is in use or spare, and not already asked for or out, can be asked for.
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
     *     borrower_phone?: string|null, purpose?: string|null, due_on?: string|null}  $data  validated
     */
    public function handle(Asset $asset, array $data, User $actor): AssetCheckout
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            // One request at a time per asset, even when two people press at once.
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);

            if (! self::available($asset)) {
                throw ValidationException::withMessages(['type' => __('asset.checkouts.not_available')]);
            }

            $userId = $data['borrower_user_id'] ?? null;

            $checkout = AssetCheckout::create([
                'asset_id' => $asset->id,
                'checkout_no' => $this->generateNumber->handle(),
                'type' => $data['type'],
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

            return $checkout;
        });
    }

    public static function available(Asset $asset): bool
    {
        return in_array($asset->status, self::AVAILABLE_STATUSES, true)
            && ! AssetCheckout::query()->where('asset_id', $asset->id)->whereIn('status', AssetCheckout::OPEN_STATUSES)->exists();
    }
}
