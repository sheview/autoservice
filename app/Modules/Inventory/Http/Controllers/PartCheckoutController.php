<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Inventory\Actions\CancelPartCheckout;
use App\Modules\Inventory\Actions\DecidePartCheckout;
use App\Modules\Inventory\Actions\PartCheckoutSheet;
use App\Modules\Inventory\Actions\RequestPartCheckout;
use App\Modules\Inventory\Actions\ReturnPartCheckout;
use App\Modules\Inventory\Actions\SearchPartCheckouts;
use App\Modules\Inventory\Http\Requests\PartCheckoutRequest;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartCheckout;
use App\Modules\Inventory\Support\PartCheckoutRow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Issuing and lending spare parts, like assets: asked for by staff who issue/lend (asset.checkout),
 * approved or rejected (asset.approve; the stock goes out then), printed for signatures, and lent
 * parts taken back. Customer accounts never see parts.
 */
class PartCheckoutController extends Controller
{
    public function index(Request $request, SearchPartCheckouts $search): Response
    {
        $can = self::abilities($request);
        abort_unless($can['request'] || $can['approve'], 403);

        $filters = SearchPartCheckouts::filtersFrom($request);

        return Inertia::render('Inventory/PartCheckouts/Index', [
            'checkouts' => $search->handle($filters)->paginate(20)->withQueryString()->through(fn (PartCheckout $checkout) => PartCheckoutRow::of($checkout)),
            'filters' => $filters,
            'statuses' => PartCheckout::STATUSES,
            'types' => PartCheckout::TYPES,
            'can' => [...$can, 'userId' => $request->user()->id],
        ]);
    }

    public function store(PartCheckoutRequest $request, Part $part, RequestPartCheckout $requestCheckout): RedirectResponse
    {
        $checkout = $requestCheckout->handle($part, $request->validated(), $request->user());

        return back()->with('success', __('inventory.part_checkouts.requested', ['no' => $checkout->checkout_no]));
    }

    public function approve(Request $request, PartCheckout $checkout, DecidePartCheckout $decide): RedirectResponse
    {
        $this->authorizeOn($request, 'approve');

        $decide->handle($checkout, true, $request->user(), $request->string('note')->trim()->value() ?: null);

        return back()->with('success', __('inventory.part_checkouts.approved', ['no' => $checkout->checkout_no]));
    }

    public function reject(Request $request, PartCheckout $checkout, DecidePartCheckout $decide): RedirectResponse
    {
        $this->authorizeOn($request, 'approve');
        $request->validate(['note' => ['required', 'string', 'max:2000']], attributes: ['note' => __('inventory.part_checkouts.fields.note')]);

        $decide->handle($checkout, false, $request->user(), $request->string('note')->trim()->value());

        return back()->with('success', __('inventory.part_checkouts.rejected', ['no' => $checkout->checkout_no]));
    }

    public function giveBack(Request $request, PartCheckout $checkout, ReturnPartCheckout $returnCheckout): RedirectResponse
    {
        $this->authorizeOn($request, 'request');
        $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $returnCheckout->handle($checkout, $request->user(), $request->string('note')->trim()->value() ?: null);

        return back()->with('success', __('inventory.part_checkouts.returned', ['no' => $checkout->checkout_no]));
    }

    /** Withdrawn by whoever asked, or by an approver. */
    public function cancel(Request $request, PartCheckout $checkout, CancelPartCheckout $cancel): RedirectResponse
    {
        $this->authorizeOn($request, null);
        abort_unless($checkout->requested_by === $request->user()->id || self::abilities($request)['approve'], 403);

        $cancel->handle($checkout, $request->user());

        return back()->with('success', __('inventory.part_checkouts.cancelled', ['no' => $checkout->checkout_no]));
    }

    /** The form as an A4 page for the browser to print (works without the PDF service). */
    public function print(Request $request, PartCheckout $checkout, PartCheckoutSheet $sheet): View
    {
        $this->authorizePrint($request, $checkout);

        return view('documents.asset-checkout', [...$sheet->handle($checkout), 'forBrowser' => true]);
    }

    public function pdf(Request $request, PartCheckout $checkout, PartCheckoutSheet $sheet, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        $this->authorizePrint($request, $checkout);

        try {
            return $renderPdf->handle('documents.asset-checkout', $sheet->handle($checkout), "{$checkout->checkout_no}.pdf");
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    /**
     * What the user may do with part issue/loan forms, for the pages.
     *
     * @return array{request: bool, approve: bool}
     */
    public static function abilities(Request $request): array
    {
        $user = $request->user();
        $staff = $user->customer_id === null && $user->can('part.view');

        return ['request' => $staff && $user->can('asset.checkout'), 'approve' => $staff && $user->can('asset.approve')];
    }

    private function authorizeOn(Request $request, ?string $ability): void
    {
        $can = self::abilities($request);
        abort_unless($can['request'] || $can['approve'], 403);
        abort_if($ability !== null && ! $can[$ability], 403);
    }

    /** Printed by whoever handles these forms, once approved (it is the hand-over paper). */
    private function authorizePrint(Request $request, PartCheckout $checkout): void
    {
        $this->authorizeOn($request, null);
        abort_unless(in_array($checkout->status, [PartCheckout::STATUS_APPROVED, PartCheckout::STATUS_RETURNED], true), 404);
    }
}
