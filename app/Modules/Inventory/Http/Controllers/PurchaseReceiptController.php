<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\HandOutPurchase;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\PurchaseIssueLines;
use App\Modules\Inventory\Actions\ReceivePurchase;
use App\Modules\Inventory\Actions\RegisterPurchaseReceipt;
use App\Modules\Inventory\Http\Requests\ReceivePurchaseForm;
use App\Modules\Inventory\Http\Requests\RegisterReceiptForm;
use App\Modules\Inventory\Models\PurchaseReceipt;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Platform\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The buyers' side of a purchase request once approved: record each delivery (some or all of it),
 * which goes into the system at once as an asset or stock of a part, and hand what came over to
 * whoever asked for it (an issue request approved and handed out in one go). A delivery recorded
 * before this that is not in the system yet is registered on its own.
 */
class PurchaseReceiptController extends Controller
{
    public function store(ReceivePurchaseForm $form, PurchaseRequest $purchaseRequest, ReceivePurchase $receive): RedirectResponse
    {
        $receipt = $receive->handle($purchaseRequest, $form->receiptData(), $form->user());
        $received = __('inventory.purchase_requests.received', ['qty' => $receipt->quantity, 'unit' => $purchaseRequest->unit, 'no' => $purchaseRequest->pr_no]);

        if ($form->boolean('hand_out') && self::handsOut($form->user())) {
            try {
                $checkoutNo = $this->handOutNow($purchaseRequest->refresh(), $form->user());
            } catch (ValidationException $e) {
                // Received and registered; only the hand-over did not happen.
                return back()->with('success', $received)->with('error', collect($e->errors())->flatten()->first());
            }

            return back()->with('success', $received.' · '.__('inventory.purchase_requests.handed_out', ['no' => $checkoutNo]));
        }

        return back()->with('success', $received);
    }

    /** What is registered and not handed out yet, to whoever asked for it. */
    public function handOut(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        Gate::authorize('view', $purchaseRequest);
        abort_unless(self::handsOut($request->user()), 403);

        $checkoutNo = $this->handOutNow($purchaseRequest, $request->user());

        return back()->with('success', __('inventory.purchase_requests.handed_out', ['no' => $checkoutNo]));
    }

    public function register(RegisterReceiptForm $form, PurchaseRequest $purchaseRequest, PurchaseReceipt $receipt, RegisterPurchaseReceipt $register): RedirectResponse
    {
        abort_unless($receipt->purchase_request_id === $purchaseRequest->id, 404);

        $receipt = $register->handle($receipt, $form->registerData(), $form->user());

        return back()->with('success', __("inventory.purchase_requests.registered.{$receipt->registered_as}", [
            'qty' => $receipt->quantity, 'unit' => $purchaseRequest->unit,
        ]));
    }

    /** Whoever may write and hand out issue requests (Asset module). */
    public static function handsOut(User $user): bool
    {
        return app(Modules::class)->enabled('asset') && $user->customer_id === null
            && $user->can('asset-checkouts.create') && $user->can('asset-checkouts.fulfill');
    }

    /** @return string the number of the issue request it was handed out on */
    private function handOutNow(PurchaseRequest $purchaseRequest, User $user): string
    {
        $lines = app(PurchaseIssueLines::class)->handle($purchaseRequest->ulid, $user);
        if ($lines === null) {
            throw ValidationException::withMessages(['hand_out' => __('asset.requests.nothing_to_hand_out')]);
        }

        return app(HandOutPurchase::class)->handle($lines, $user)->request_no;
    }
}
