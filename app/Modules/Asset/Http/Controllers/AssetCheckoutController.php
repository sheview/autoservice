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
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Issuing and lending assets: asked for (asset-checkouts.request for oneself, asset-checkouts.create
 * for anyone), approved or rejected (asset-checkouts.approve), printed for signatures, and taken
 * back (asset-checkouts.return). Each within the scope the user's role grants (SearchCheckouts).
 */
class AssetCheckoutController extends Controller
{
    public function index(Request $request, SearchCheckouts $search): Response
    {
        $user = $request->user();
        abort_unless($user->can('asset-checkouts.view'), 403);

        $filters = SearchCheckouts::filtersFrom($request);

        return Inertia::render('Asset/Checkouts/Index', [
            'checkouts' => $search->handle($user, $filters)->paginate(20)->withQueryString()
                ->through(fn (AssetCheckout $checkout) => self::row($checkout, $user)),
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
        $user = $request->user();
        abort_unless(CheckoutRequest::canAsk($user), 403);
        $search = $request->string('search')->trim()->value();
        $forSelf = CheckoutRequest::onlyForSelf($user);

        return Inertia::render('Asset/Checkouts/Create', [
            'search' => $search,
            'groups' => fn () => $search === '' ? [] : $available->handle($user, $search),
            // Asking only for oneself: nobody to choose.
            'borrowers' => $forSelf ? [] : $usersWithPermission->handle('assets.view')->sortBy('name')->map(fn ($u) => $u->only(['id', 'name']))->values(),
            'forSelf' => $forSelf,
            'contracts' => fn () => $modules->enabled('contract') ? app(ContractOptions::class)->handle() : [],
            'canPurchase' => $modules->enabled('inventory') && $user->can('purchase-requests.create'),
        ]);
    }

    public function store(CheckoutRequest $request, Asset $asset, RequestCheckout $requestCheckout): RedirectResponse
    {
        $checkout = $requestCheckout->handle($asset, $request->validated(), $request->user());

        // From the search page: on to the list, where the request now waits.
        return ($request->boolean('from_search') && $request->user()->can('asset-checkouts.view') ? redirect()->route('asset.checkouts.index') : back())
            ->with('success', __('asset.checkouts.requested', ['no' => $checkout->checkout_no]));
    }

    public function approve(Request $request, AssetCheckout $checkout, DecideCheckout $decide): RedirectResponse
    {
        $this->authorizeOn($request, $checkout, 'asset-checkouts.approve');

        $decide->handle($checkout, true, $request->user(), $request->string('note')->trim()->value() ?: null);

        return back()->with('success', __('asset.checkouts.approved', ['no' => $checkout->checkout_no]));
    }

    public function reject(Request $request, AssetCheckout $checkout, DecideCheckout $decide): RedirectResponse
    {
        $this->authorizeOn($request, $checkout, 'asset-checkouts.approve');
        $request->validate(['note' => ['required', 'string', 'max:2000']], attributes: ['note' => __('asset.checkouts.fields.note')]);

        $decide->handle($checkout, false, $request->user(), $request->string('note')->trim()->value());

        return back()->with('success', __('asset.checkouts.rejected', ['no' => $checkout->checkout_no]));
    }

    public function giveBack(Request $request, AssetCheckout $checkout, ReturnCheckout $returnCheckout): RedirectResponse
    {
        $this->authorizeOn($request, $checkout, 'asset-checkouts.return');
        $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $returnCheckout->handle($checkout, $request->user(), $request->string('note')->trim()->value() ?: null);

        return back()->with('success', __('asset.checkouts.returned', ['no' => $checkout->checkout_no]));
    }

    /** Withdrawn by whoever asked, or by an approver. */
    public function cancel(Request $request, AssetCheckout $checkout, CancelCheckout $cancel): RedirectResponse
    {
        $this->authorizeOn($request, $checkout, null);
        $user = $request->user();
        abort_unless(self::actions($checkout, $user)['cancel'], 403);

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
     * What the user may do with issue/loan forms, for the pages. request = may ask (forSelf: only
     * for themself); approve / return = holds the permission (whether a form is within reach comes
     * with the form: row()).
     *
     * @return array{view: bool, request: bool, forSelf: bool, approve: bool, return: bool}
     */
    public static function abilities(Request $request): array
    {
        $user = $request->user();

        return [
            'view' => $user->can('asset-checkouts.view'),
            'request' => CheckoutRequest::canAsk($user),
            'forSelf' => CheckoutRequest::onlyForSelf($user),
            'approve' => $user->can('asset-checkouts.approve'),
            'return' => $user->can('asset-checkouts.return'),
        ];
    }

    /**
     * A form as the pages show it, with what the user may do with it now.
     *
     * @return array<string, mixed>
     */
    public static function row(AssetCheckout $checkout, User $user): array
    {
        return [...CheckoutRow::of($checkout), 'actions' => self::actions($checkout, $user)];
    }

    /**
     * Approve / reject and take back: each needs its permission, with the form in its scope.
     * Cancel: whoever asked, or an approver who reaches the form.
     *
     * @return array{approve: bool, return: bool, cancel: bool}
     */
    public static function actions(AssetCheckout $checkout, User $user): array
    {
        $approve = $user->can('asset-checkouts.approve') && SearchCheckouts::covers($checkout, $user, 'asset-checkouts.approve');

        return [
            'approve' => $approve,
            'return' => $user->can('asset-checkouts.return') && SearchCheckouts::covers($checkout, $user, 'asset-checkouts.return'),
            'cancel' => $approve || (int) $checkout->requested_by === $user->id,
        ];
    }

    /**
     * The form must be within the user's reach (asset-checkouts.view, the action's permission, or
     * their own request), otherwise 404 as if it were not there; then the action needs its
     * permission with the form in its scope (403).
     */
    private function authorizeOn(Request $request, AssetCheckout $checkout, ?string $permission): void
    {
        $user = $request->user();
        abort_if($checkout->asset === null, 404);

        $reaches = fn (string $p) => $user->can($p) && SearchCheckouts::covers($checkout, $user, $p);
        $allowed = $permission !== null && $reaches($permission);
        abort_unless($allowed || $reaches('asset-checkouts.view') || (int) $checkout->requested_by === $user->id, 404);
        abort_if($permission !== null && ! $allowed, 403);
    }

    /** Printed by whoever handles the form, once approved (it is the hand-over paper). */
    private function authorizePrint(Request $request, AssetCheckout $checkout): void
    {
        $user = $request->user();
        $this->authorizeOn($request, $checkout, null);
        $actions = self::actions($checkout, $user);
        abort_unless($user->can('asset-checkouts.view') || $actions['approve'] || $actions['return'], 403);
        abort_unless(in_array($checkout->status, [AssetCheckout::STATUS_APPROVED, AssetCheckout::STATUS_RETURNED], true), 404);
    }
}
