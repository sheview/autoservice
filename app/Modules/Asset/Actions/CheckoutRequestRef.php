<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;

/**
 * One issue/loan request by its ulid, as other modules point to it (a purchase request asked
 * from it): id, number and status. Null when there is none or the user may not see it.
 */
class CheckoutRequestRef
{
    /** @return array{id: int, ulid: string, request_no: string, status: string}|null */
    public function handle(string $ulid, User $user): ?array
    {
        $checkout = CheckoutRequest::query()->where('ulid', $ulid)->first();

        return $checkout && $user->can('view', $checkout) ? $checkout->only(['id', 'ulid', 'request_no', 'status']) : null;
    }
}
