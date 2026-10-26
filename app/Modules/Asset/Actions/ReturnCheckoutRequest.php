<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Take all back": every asset still out on the request comes back in full, in the condition
 * given (ReturnCheckoutItem, one line at a time; the last one closes the request). Parts are used
 * up and are returned line by line with a reason. Returns how many lines came back and how many
 * were left (one that could not be taken back).
 */
class ReturnCheckoutRequest
{
    public function __construct(private ReturnCheckoutItem $return) {}

    /**
     * @return array{done: int, left: int}
     */
    public function handle(CheckoutRequest $request, User $actor, ?string $condition = null): array
    {
        $done = 0;
        $left = 0;

        foreach ($request->items()->orderBy('id')->get()->filter(fn (CheckoutItem $item) => $item->returnable()) as $item) {
            try {
                $this->return->handle($item, $actor, $item->outstanding(), $condition);
                $done++;
            } catch (ValidationException) {
                $left++;
            }
        }

        return ['done' => $done, 'left' => $left];
    }
}
