<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AvailableForCheckout;
use App\Modules\Asset\Actions\CancelCheckout;
use App\Modules\Asset\Actions\CheckoutSheet;
use App\Modules\Asset\Actions\DecideCheckout;
use App\Modules\Asset\Actions\RequestCheckout;
use App\Modules\Asset\Actions\ReturnCheckout;
use App\Modules\Asset\Actions\SearchCheckouts;
use App\Modules\Asset\Http\Requests\CheckoutRequest;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCheckout;
use App\Modules\Asset\Support\CheckoutRow;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Platform\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Issuing and lending assets: asked for (asset.checkout), approved or rejected (asset.approve),
 * printed for signatures, and taken back (asset.checkout). Only for assets the user may see.
 */
class AssetCheckoutController extends Controller
{
    public function index(Request $request, SearchCheckouts $search): Response
    {
        $user = $request->user();
        abort_unless($user->can('asset.checkout') || $user->can('asset.approve'), 403);

        $filters = SearchCheckouts::filtersFrom($request);

        return Inertia::render('Asset/Checkouts/Index', [
            'checkouts' => $search->handle($user, $filters)->paginate(20)->withQueryString()->through(fn (AssetCheckout $checkout) => CheckoutRow::of($checkout)),
            'filters' => $filters,
            'statuses' => AssetCheckout::STATUSES,
            'types' => AssetCheckout::TYPES,
            'can' => self::abilities($request),
        ]);
    }

    /**
     * Asking for something: search the spare devices first (?search=), pick one, fill in the
     * request. Nothing found: a purchase request instead (Inventory module), starting from the search.
     */
    public function create(Request $request, AvailableForCheckout $available, UsersWithPermission $usersWithPermission, Modules $modules): Response
    {
        abort_unless($request->user()->can('asset.checkout'), 403);
        $search = $request->string('search')->trim()->value();

        return Inertia::render('Asset/Checkouts/Create', [
            'search' => $search,
            'groups' => fn () => $search === '' ? [] : $available->handle($request->user(), $search),
            'borrowers' => $usersWithPermission->handle('asset.view')->sortBy('name')->map(fn ($u) => $u->only(['id', 'name']))->values(),
            'canPurchase' => $modules->enabled('inventory') && $request->user()->can('purchase.request'),
        ]);
    }

    public function store(CheckoutRequest $request, Asset $asset, RequestCheckout $requestCheckout): RedirectResponse
    {
        $checkout = $requestCheckout->handle($asset, $request->validated(), $request->user());

        // From the search page: on to the list, where the request now waits.
        return ($request->boolean('from_search') ? redirect()->route('asset.checkouts.index') : back())
            ->with('success', __('asset.checkouts.requested', ['no' => $checkout->checkout_no]));
    }

    public function approve(Request $request, AssetCheckout $checkout, DecideCheckout $decide): RedirectResponse
    {
        $this->authorizeOn($request, $checkout, 'asset.approve');

        $decide->handle($checkout, true, $request->user(), $request->string('note')->trim()->value() ?: null);

        return back()->with('success', __('asset.checkouts.approved', ['no' => $checkout->checkout_no]));
    }

    public function reject(Request $request, AssetCheckout $checkout, DecideCheckout $decide): RedirectResponse
    {
        $this->authorizeOn($request, $checkout, 'asset.approve');
        $request->validate(['note' => ['required', 'string', 'max:2000']], attributes: ['note' => __('asset.checkouts.fields.note')]);

        $decide->handle($checkout, false, $request->user(), $request->string('note')->trim()->value());

        return back()->with('success', __('asset.checkouts.rejected', ['no' => $checkout->checkout_no]));
    }

    public function giveBack(Request $request, AssetCheckout $checkout, ReturnCheckout $returnCheckout): RedirectResponse
    {
        $this->authorizeOn($request, $checkout, 'asset.checkout');
        $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $returnCheckout->handle($checkout, $request->user(), $request->string('note')->trim()->value() ?: null);

        return back()->with('success', __('asset.checkouts.returned', ['no' => $checkout->checkout_no]));
    }

    /** Withdrawn by whoever asked, or by an approver. */
    public function cancel(Request $request, AssetCheckout $checkout, CancelCheckout $cancel): RedirectResponse
    {
        $this->authorizeOn($request, $checkout, null);
        $user = $request->user();
        abort_unless($checkout->requested_by === $user->id || $user->can('asset.approve'), 403);

        $cancel->handle($checkout, $user);

        return back()->with('success', __('asset.checkouts.cancelled', ['no' => $checkout->checkout_no]));
    }

    /** The form as an A4 page for the browser to print (works without the PDF service). */
    public function print(Request $request, AssetCheckout $checkout, CheckoutSheet $sheet): View
    {
        $this->authorizePrint($request, $checkout);

        return view('documents.asset-checkout', [...$sheet->handle($checkout), 'forBrowser' => true]);
    }

    public function pdf(Request $request, AssetCheckout $checkout, CheckoutSheet $sheet, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        $this->authorizePrint($request, $checkout);

        try {
            return $renderPdf->handle('documents.asset-checkout', $sheet->handle($checkout), "{$checkout->checkout_no}.pdf");
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    /**
     * What the user may do with issue/loan forms, for the pages.
     *
     * @return array{request: bool, approve: bool}
     */
    public static function abilities(Request $request): array
    {
        return ['request' => $request->user()->can('asset.checkout'), 'approve' => $request->user()->can('asset.approve')];
    }

    private function authorizeOn(Request $request, AssetCheckout $checkout, ?string $permission): void
    {
        $user = $request->user();
        abort_unless($checkout->asset !== null && $user->can('view', $checkout->asset), 404);
        abort_if($permission !== null && ! $user->can($permission), 403);
    }

    /** Printed by whoever handles these forms, once approved (it is the hand-over paper). */
    private function authorizePrint(Request $request, AssetCheckout $checkout): void
    {
        $this->authorizeOn($request, $checkout, null);
        abort_unless($request->user()->can('asset.checkout') || $request->user()->can('asset.approve'), 403);
        abort_unless(in_array($checkout->status, [AssetCheckout::STATUS_APPROVED, AssetCheckout::STATUS_RETURNED], true), 404);
    }
}
