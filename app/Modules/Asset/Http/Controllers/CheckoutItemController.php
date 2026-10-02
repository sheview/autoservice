<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\BackorderCheckoutItem;
use App\Modules\Asset\Actions\CancelCheckoutItem;
use App\Modules\Asset\Actions\FulfillCheckoutItem;
use App\Modules\Asset\Actions\ReturnCheckoutItem;
use App\Modules\Asset\Models\CheckoutItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What is done to one line of an issue/loan request: hand out (some of) it, backorder what is
 * missing (and order it), give up what is left (with a reason), take a lent asset back.
 */
class CheckoutItemController extends Controller
{
    public function fulfill(Request $request, CheckoutItem $item, FulfillCheckoutItem $fulfill): RedirectResponse
    {
        Gate::authorize('fulfill', $item->request);
        $data = $request->validate(['qty' => ['required', 'integer', 'min:1']]);

        $fulfill->handle($item, (int) $data['qty'], $request->user());

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
