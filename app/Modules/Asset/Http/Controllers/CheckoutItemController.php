<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\BackorderCheckoutItem;
use App\Modules\Asset\Actions\CancelCheckoutItem;
use App\Modules\Asset\Actions\FulfillCheckoutItem;
use App\Modules\Asset\Actions\ReturnCheckoutItem;
use App\Modules\Asset\Actions\ReturnCheckoutPart;
use App\Modules\Asset\Models\CheckoutItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What is done to one line of an issue/loan request: hand out (some of) it, backorder what is
 * missing (and order it), give up what is left (with a reason), take a lent asset back, take
 * parts handed out back into stock (with a reason).
 */
class CheckoutItemController extends Controller
{
    public function fulfill(Request $request, CheckoutItem $item, FulfillCheckoutItem $fulfill): RedirectResponse
    {
        Gate::authorize('fulfill', $item->request);
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            // The pieces handed out, for a part followed by serial number.
            'unit_ids' => ['nullable', 'array', 'max:1000'],
            'unit_ids.*' => ['integer'],
        ]);

        $fulfill->handle($item, (int) $data['qty'], $request->user(), $data['unit_ids'] ?? []);

        return back()->with('success', __('asset.requests.fulfilled', ['item' => $item->item_name, 'qty' => $data['qty']]));
    }

    /** ?order=1 also opens a purchase request for what is missing. */
    public function backorder(Request $request, CheckoutItem $item, BackorderCheckoutItem $backorder): RedirectResponse
    {
        Gate::authorize('fulfill', $item->request);
        $order = $request->boolean('order');
        abort_if($order && ! $request->user()->can('purchase-requests.create'), 403);

        $backorder->handle($item, $request->user(), $order);

        return back()->with('success', __($order ? 'asset.requests.ordered' : 'asset.requests.backordered', ['item' => $item->item_name]));
    }

    public function cancel(Request $request, CheckoutItem $item, CancelCheckoutItem $cancel): RedirectResponse
    {
        Gate::authorize('fulfill', $item->request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], attributes: ['reason' => __('asset.requests.fields.reject_reason')]);

        $cancel->handle($item, $request->user(), $data['reason']);

        return back()->with('success', __('asset.requests.item_cancelled', ['item' => $item->item_name]));
    }

    /** Parts handed out on the line, back into stock with the reason (the pieces, for a tracked part). */
    public function returnParts(Request $request, CheckoutItem $item, ReturnCheckoutPart $return): RedirectResponse
    {
        Gate::authorize('returnItems', $item->request);
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            'unit_ids' => ['nullable', 'array', 'max:1000'],
            'unit_ids.*' => ['integer'],
        ], attributes: ['reason' => __('asset.requests.fields.return_reason')]);

        $return->handle($item, $request->user(), (int) $data['qty'], $data['reason'], $data['unit_ids'] ?? []);

        return back()->with('success', __('asset.requests.returned', ['item' => $item->item_name, 'qty' => $data['qty']]));
    }

    public function giveBack(Request $request, CheckoutItem $item, ReturnCheckoutItem $return): RedirectResponse
    {
        Gate::authorize('returnItems', $item->request);
        $data = $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'condition' => ['nullable', 'string', 'max:255'],
        ]);

        $return->handle($item, $request->user(), (int) $data['qty'], $data['condition'] ?? null);

        return back()->with('success', __('asset.requests.returned', ['item' => $item->item_name, 'qty' => $data['qty']]));
    }
}
