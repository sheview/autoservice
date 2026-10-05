<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\ApproveCheckoutRequest;
use App\Modules\Asset\Actions\AssetHeldQuantities;
use App\Modules\Asset\Actions\CancelCheckoutRequest;
use App\Modules\Asset\Actions\CloseCheckoutRequest;
use App\Modules\Asset\Actions\RejectCheckoutRequest;
use App\Modules\Asset\Actions\SaveCheckoutRequest;
use App\Modules\Asset\Actions\SearchAssets;
use App\Modules\Asset\Actions\SearchCheckoutLines;
use App\Modules\Asset\Actions\SearchCheckoutRequests;
use App\Modules\Asset\Actions\SubmitCheckoutRequest;
use App\Modules\Asset\Http\Requests\CheckoutRequestForm;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\CheckoutFulfillment;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Asset\Models\CheckoutRequest;
use App\Modules\Asset\Support\CheckoutStatus;
use App\Modules\Asset\Support\RequestRow;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Actions\FindPartUnits;
use App\Modules\Inventory\Actions\IssuedPartSerials;
use App\Modules\Inventory\Actions\PartsForCheckout;
use App\Modules\Inventory\Actions\PurchaseIssueLines;
use App\Modules\Inventory\Actions\PurchaseRequestLabels;
use App\Modules\Platform\CrossTenant\SharedRequests;
use App\Modules\Platform\Support\Modules;
use App\Modules\Service\Actions\TicketsForCheckout;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Issue/loan requests with lines (checkout-schema.md): the list and its queues (to approve, to
 * hand out, backorders, returns), the request form (draft / send), the request page with the
 * decisions, and the printed form. Line actions are in CheckoutItemController.
 */
class CheckoutRequestController extends Controller
{
    public function __construct(private Modules $modules) {}

    public function index(Request $request, SearchCheckoutRequests $search, SearchCheckoutLines $lines): Response
    {
        $user = $request->user();
        $can = $this->abilities($user);
        // Whoever may ask sees at least their own requests (SearchCheckoutRequests::visibleTo).
        abort_unless($can['view'] || $can['create'] || $can['approve'] || $can['fulfill'] || $can['return'], 403);

        $filters = SearchCheckoutRequests::filtersFrom($request);
        $lineTab = in_array($filters['tab'], SearchCheckoutRequests::LINE_TABS, true);

        return Inertia::render('Asset/Requests/Index', [
            'filters' => $filters,
            'requests' => $lineTab ? null : $search->handle($user, $filters)->paginate(20)->withQueryString()
                ->through(fn (CheckoutRequest $row) => RequestRow::of($row)),
            'lines' => $lineTab ? $this->linesPage($lines->handle($user, $filters)->paginate(30)->withQueryString()) : null,
            'counts' => [
                'approve' => $can['approve'] ? $search->handle($user, ['tab' => 'approve'])->count() : null,
                'fulfill' => $can['fulfill'] ? $search->handle($user, ['tab' => 'fulfill'])->count() : null,
                'backorders' => $can['fulfill'] ? $lines->handle($user, ['tab' => 'backorders'])->count() : null,
                'returns' => $can['return'] ? $lines->handle($user, ['tab' => 'returns', 'overdue' => true])->count() : null,
            ],
            'statuses' => CheckoutRequest::STATUSES,
            // Pieces of parts whose serial number matches the search, with their history (Inventory module).
            'serialHits' => $this->modules->enabled('inventory') && $user->can('parts.view') ? app(FindPartUnits::class)->handle($filters['search'] ?? '') : [],
            'can' => $can,
        ]);
    }

    /**
     * The request form. ?asset= (ulid) or ?part= (id) starts it with that line; ?ticket= (id) with that ticket.
     */
    public function create(Request $request, PartsForCheckout $parts): Response
    {
        Gate::authorize('create', CheckoutRequest::class);
        $user = $request->user();

        $first = null;
        if ($request->filled('asset')) {
            $asset = SearchAssets::askableBy(Asset::query(), $user)->where('ulid', $request->string('asset'))->first();
            $first = $asset ? $this->assetOption($asset, app(AssetHeldQuantities::class)->handle([$asset->id])[$asset->id] ?? 0) : null;
        } elseif ($request->filled('part') && $this->modules->enabled('inventory')) {
            $part = $parts->handle([$request->integer('part')])[$request->integer('part')] ?? null;
            $first = $part && $part['is_active'] ? $this->partOption($part) : null;
        }
        $ticket = $request->filled('ticket') && $this->modules->enabled('service')
            ? (app(TicketsForCheckout::class)->handle($user, [$request->integer('ticket')])[$request->integer('ticket')] ?? null)
            : null;

        $purchase = $this->purchaseItems($request);

        return Inertia::render('Asset/Requests/Form', [
            'request' => null,
            ...$this->formProps($request, $purchase['contract_id'] ?? null),
            'firstItem' => $first,
            'ticket' => $ticket,
            'purchase' => $purchase,
        ]);
    }

    public function store(CheckoutRequestForm $form, SaveCheckoutRequest $save, SubmitCheckoutRequest $submit): RedirectResponse
    {
        $checkout = $save->handle(null, $form->requestData(), $form->user());

        return $this->afterSave($form, $checkout, $submit);
    }

    public function show(Request $request, CheckoutRequest $checkout, PartsForCheckout $parts): Response
    {
        Gate::authorize('view', $checkout);
        $user = $request->user();
        $checkout->load(['items.asset:id,ulid', 'items.fulfillments']);

        $partIds = $checkout->items->where('item_type', CheckoutItem::TYPE_PART)->pluck('part_id')->all();
        $partRows = $partIds === [] || ! $this->modules->enabled('inventory') ? [] : $parts->handle($partIds);
        $onHand = array_map(fn (array $p) => $p['qty_on_hand'], $partRows);
        // Pieces by serial number: whether each part goes by them, and those out on each line.
        $serials = $partRows === [] ? [] : app(IssuedPartSerials::class)->handle($checkout->items->where('item_type', CheckoutItem::TYPE_PART)->pluck('id')->all());
        $purchases = $this->modules->enabled('inventory') ? app(PurchaseRequestLabels::class)->handle($checkout->items->pluck('purchase_request_id')->all()) : [];

        $row = RequestRow::of($checkout, [
            'ticket' => $checkout->ticket_id && $this->modules->enabled('service')
                ? (app(TicketsForCheckout::class)->handle($user, [$checkout->ticket_id])[$checkout->ticket_id] ?? null) : null,
            'contract' => $checkout->contract_id && $this->modules->enabled('contract')
                ? (app(ContractLabels::class)->handle([$checkout->contract_id])[$checkout->contract_id] ?? null) : null,
        ], $purchases, $onHand);
        $row['items'] = collect($row['items'])->map(fn (array $item) => [
            ...$item,
            'fulfillments' => $checkout->items->firstWhere('id', $item['id'])->fulfillments
                ->map(fn (CheckoutFulfillment $f) => ['qty' => $f->qty, 'by' => $f->fulfilled_by_name, 'at' => $f->fulfilled_at?->toIso8601String()])->values(),
            'track_serial' => $item['part_id'] ? (bool) ($partRows[$item['part_id']]['track_serial'] ?? false) : false,
            'serials' => $serials[$item['id']] ?? null,
        ])->all();

        return Inertia::render('Asset/Requests/Show', [
            'request' => $row,
            // Asked by a person of another company through a share: which company, for which job.
            'askedBy' => app(SharedRequests::class)->askedBy($checkout->id),
            'can' => [
                'edit' => $user->can('update', $checkout),
                'submit' => $user->can('update', $checkout),
                'cancel' => $user->can('cancel', $checkout),
                'approve' => $checkout->status === CheckoutRequest::STATUS_PENDING && $user->can('approve', $checkout),
                'fulfill' => in_array($checkout->status, [CheckoutRequest::STATUS_APPROVED, CheckoutRequest::STATUS_PARTIAL], true) && $user->can('fulfill', $checkout),
                'order' => $this->modules->enabled('inventory') && $user->can('purchase-requests.create') && $user->can('fulfill', $checkout),
                'return' => $user->can('returnItems', $checkout),
                'close' => CheckoutStatus::closable($checkout) && ($user->can('fulfill', $checkout) || $user->can('approve', $checkout)),
                'print' => ! in_array($checkout->status, [CheckoutRequest::STATUS_DRAFT, CheckoutRequest::STATUS_PENDING, CheckoutRequest::STATUS_REJECTED, CheckoutRequest::STATUS_CANCELLED], true),
            ],
        ]);
    }

    public function edit(Request $request, CheckoutRequest $checkout): Response
    {
        Gate::authorize('update', $checkout);
        $checkout->load('items.asset:id,ulid,quantity');

        // What each line may still take (a draft holds nothing), and whether it is counted.
        $held = app(AssetHeldQuantities::class)->handle($checkout->items->pluck('asset_id')->filter()->all());
        $partIds = $checkout->items->where('item_type', CheckoutItem::TYPE_PART)->pluck('part_id')->all();
        $parts = $partIds === [] || ! $this->modules->enabled('inventory') ? [] : app(PartsForCheckout::class)->handle($partIds);
        $row = RequestRow::of($checkout);
        $row['items'] = collect($row['items'])->map(function (array $item) use ($checkout, $held, $parts) {
            $line = $checkout->items->firstWhere('id', $item['id']);
            $asset = $line->item_type === CheckoutItem::TYPE_ASSET ? $line->asset : null;

            return [
                ...$item,
                'lot' => $asset ? (int) $asset->quantity > 1 : true,
                'available' => $asset ? max(0, (int) $asset->quantity - ($held[$asset->id] ?? 0)) : ($parts[$line->part_id]['qty_on_hand'] ?? 0),
            ];
        })->all();

        return Inertia::render('Asset/Requests/Form', [
            'request' => $row,
            ...$this->formProps($request, $checkout->contract_id),
            'firstItem' => null,
            'ticket' => $checkout->ticket_id ? (app(TicketsForCheckout::class)->handle($request->user(), [$checkout->ticket_id])[$checkout->ticket_id] ?? null) : null,
            // ?purchase_request=: the draft it was asked from gets what the purchase brought.
            'purchase' => $this->purchaseItems($request),
        ]);
    }

    public function update(CheckoutRequestForm $form, CheckoutRequest $checkout, SaveCheckoutRequest $save, SubmitCheckoutRequest $submit): RedirectResponse
    {
        $checkout = $save->handle($checkout, $form->requestData(), $form->user());

        return $this->afterSave($form, $checkout, $submit);
    }

    /**
     * Sent for approval, or kept as a draft. With then_purchase the draft is kept and the purchase
     * request form opens for what was not found, tied to this request.
     */
    private function afterSave(CheckoutRequestForm $form, CheckoutRequest $checkout, SubmitCheckoutRequest $submit): RedirectResponse
    {
        if ($form->filled('then_purchase') && $this->modules->enabled('inventory') && $form->user()->can('purchase-requests.create')) {
            return redirect()->route('inventory.purchase-requests.create', ['item' => $form->input('then_purchase'), 'checkout' => $checkout->ulid])
                ->with('success', __('asset.requests.saved', ['no' => $checkout->request_no]));
        }
        if ($form->boolean('submit')) {
            $checkout = $submit->handle($checkout, $form->user());
        }

        return redirect()->route('asset.requests.show', $checkout)
            ->with('success', __($form->boolean('submit') ? 'asset.requests.submitted' : 'asset.requests.saved', ['no' => $checkout->request_no]));
    }

    public function submit(Request $request, CheckoutRequest $checkout, SubmitCheckoutRequest $submit): RedirectResponse
    {
        Gate::authorize('update', $checkout);
        $submit->handle($checkout, $request->user());

        return back()->with('success', __('asset.requests.submitted', ['no' => $checkout->request_no]));
    }

    /** { lines: { itemId: { qty, reject_reason } } }: everything as asked, unless a line says otherwise. */
    public function approve(Request $request, CheckoutRequest $checkout, ApproveCheckoutRequest $approve): RedirectResponse
    {
        Gate::authorize('approve', $checkout);
        $data = $request->validate([
            'lines' => ['array'],
            'lines.*.qty' => ['nullable', 'integer', 'min:0'],
            'lines.*.reject_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $approve->handle($checkout, $request->user(), $data['lines'] ?? []);

        return back()->with('success', __('asset.requests.decided', ['no' => $checkout->request_no]));
    }

    public function reject(Request $request, CheckoutRequest $checkout, RejectCheckoutRequest $reject): RedirectResponse
    {
        Gate::authorize('approve', $checkout);
        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:1000']], attributes: ['reject_reason' => __('asset.requests.fields.reject_reason')]);

        $reject->handle($checkout, $request->user(), $data['reject_reason']);

        return back()->with('success', __('asset.requests.rejected', ['no' => $checkout->request_no]));
    }

    public function cancel(Request $request, CheckoutRequest $checkout, CancelCheckoutRequest $cancel): RedirectResponse
    {
        Gate::authorize('cancel', $checkout);
        $cancel->handle($checkout, $request->user());

        return back()->with('success', __('asset.requests.cancelled', ['no' => $checkout->request_no]));
    }

    public function close(Request $request, CheckoutRequest $checkout, CloseCheckoutRequest $close): RedirectResponse
    {
        abort_unless($request->user()->can('fulfill', $checkout) || $request->user()->can('approve', $checkout), 403);
        $close->handle($checkout, $request->user());

        return back()->with('success', __('asset.requests.closed', ['no' => $checkout->request_no]));
    }

    /** Assets that may be asked for and parts, for the line picker (?q=). */
    public function items(Request $request, AssetHeldQuantities $held, PartsForCheckout $parts): JsonResponse
    {
        Gate::authorize('create', CheckoutRequest::class);
        $search = $request->string('q')->trim()->value();

        $assets = SearchAssets::askableBy(Asset::query(), $request->user())
            ->whereIn('status', [Asset::STATUS_IN_USE, Asset::STATUS_SPARE])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('asset_code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('serial_number', 'ilike', "%{$search}%")->orWhere('model', 'ilike', "%{$search}%")))
            ->orderBy('name')->limit(30)->get();
        $heldBy = $held->handle($assets->pluck('id')->all());

        return response()->json([
            'assets' => $assets->map(fn (Asset $asset) => $this->assetOption($asset, $heldBy[$asset->id] ?? 0))->values(),
            'parts' => $this->modules->enabled('inventory')
                ? collect($parts->handle(null, $search))->map(fn (array $part) => $this->partOption($part))->values()
                : [],
        ]);
    }

    /** Open tickets the user may see, for the request's ticket (?q=). */
    public function tickets(Request $request, TicketsForCheckout $tickets): JsonResponse
    {
        Gate::authorize('create', CheckoutRequest::class);

        return response()->json($this->modules->enabled('service') ? array_values($tickets->handle($request->user(), null, $request->string('q')->trim()->value())) : []);
    }

    /** The form as an A4 page for the browser to print (works without the PDF service). */
    public function print(Request $request, CheckoutRequest $checkout, TenantContext $context): View
    {
        $this->authorizePrint($request, $checkout);

        return view('documents.checkout-request', [...$this->sheet($checkout, $context), 'forBrowser' => true]);
    }

    public function pdf(Request $request, CheckoutRequest $checkout, TenantContext $context, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        $this->authorizePrint($request, $checkout);

        try {
            return $renderPdf->handle('documents.checkout-request', $this->sheet($checkout, $context), "{$checkout->request_no}.pdf");
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    /** The delivery note of what was handed out, as an A4 page for the browser to print. */
    public function deliveryNotePrint(Request $request, CheckoutRequest $checkout, TenantContext $context): View
    {
        return view('documents.delivery-note', [...$this->deliverySheet($request, $checkout, $context), 'forBrowser' => true]);
    }

    /** The delivery note (ใบส่งสินค้า) of what was handed out so far, as a PDF. */
    public function deliveryNote(Request $request, CheckoutRequest $checkout, TenantContext $context, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        $sheet = $this->deliverySheet($request, $checkout, $context);

        try {
            return $renderPdf->handle('documents.delivery-note', $sheet, "DN-{$checkout->request_no}.pdf");
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    /**
     * What a delivery note shows: the lines handed out (with the serials of their assets and the
     * purchase that bought them), the project, who handed them out and when. Only once something
     * has been handed out.
     *
     * @return array<string, mixed>
     */
    private function deliverySheet(Request $request, CheckoutRequest $checkout, TenantContext $context): array
    {
        Gate::authorize('view', $checkout);
        $checkout->load(['items.fulfillments', 'items.asset.serials']);
        $items = $checkout->items->where('qty_fulfilled', '>', 0)->values();
        abort_if($items->isEmpty(), 404);

        $fulfillments = $items->flatMap(fn (CheckoutItem $item) => $item->fulfillments);

        return [
            ...$this->sheet($checkout, $context),
            'items' => $items,
            'serials' => $items->filter(fn (CheckoutItem $item) => $item->asset !== null)
                ->mapWithKeys(fn (CheckoutItem $item) => [$item->asset_id => $item->asset->serials->pluck('serial_number')->take($item->qty_fulfilled)->all()])
                ->all(),
            'purchases' => $this->modules->enabled('inventory') ? app(PurchaseRequestLabels::class)->handle($items->pluck('purchase_request_id')->all()) : [],
            'project' => $checkout->contract_id && $this->modules->enabled('contract')
                ? (app(ContractLabels::class)->handle([$checkout->contract_id])[$checkout->contract_id] ?? null)
                : null,
            'senders' => $fulfillments->pluck('fulfilled_by_name')->filter()->unique()->implode(', '),
            'deliveredAt' => $fulfillments->max('fulfilled_at') ?? $checkout->approved_at,
        ];
    }

    /**
     * @return array{view: bool, create: bool, forOthers: bool, approve: bool, fulfill: bool, return: bool}
     */
    private function abilities(User $user): array
    {
        $staff = $user->customer_id === null;

        return [
            'view' => $staff && $user->can('asset-checkouts.view'),
            'create' => $user->can('create', CheckoutRequest::class),
            'forOthers' => $user->can('createForOthers', CheckoutRequest::class),
            'approve' => $staff && $user->can('asset-checkouts.approve'),
            'fulfill' => $staff && $user->can('asset-checkouts.fulfill'),
            'return' => $staff && $user->can('asset-checkouts.return'),
        ];
    }

    /** @return array<string, mixed> */
    private function formProps(Request $request, ?int $contractId = null): array
    {
        $user = $request->user();

        return [
            'borrowers' => $user->can('createForOthers', CheckoutRequest::class)
                ? app(UsersWithPermission::class)->handle('assets.view')->sortBy('name')->map(fn ($u) => $u->only(['id', 'name']))->values()
                : [],
            'contracts' => $this->modules->enabled('contract') ? app(ContractOptions::class)->handle($contractId) : [],
            'partsEnabled' => $this->modules->enabled('inventory'),
            'maxItems' => CheckoutRequestForm::MAX_ITEMS,
            // Nothing free to hand out: offer to open a purchase request for it instead.
            'canPurchase' => $this->modules->enabled('inventory') && $user->can('purchase-requests.create'),
            'can' => $this->abilities($user),
        ];
    }

    /**
     * ?purchase_request={ulid}: what that purchase brought and is not handed out yet, as lines
     * tied to it (Inventory module), for whom it was bought and for which project.
     *
     * @return array{ulid: string, pr_no: string, borrower_user_id: int|null, contract_id: int|null, items: list<array<string, mixed>>}|null
     */
    private function purchaseItems(Request $request): ?array
    {
        if (! $request->filled('purchase_request') || ! $this->modules->enabled('inventory')) {
            return null;
        }
        $purchase = app(PurchaseIssueLines::class)->handle($request->string('purchase_request')->value(), $request->user());
        if ($purchase === null) {
            return null;
        }

        $assetIds = collect($purchase['lines'])->where('item_type', CheckoutItem::TYPE_ASSET)->pluck('id')->all();
        $partIds = collect($purchase['lines'])->where('item_type', CheckoutItem::TYPE_PART)->pluck('id')->all();
        $assets = Asset::query()->whereKey($assetIds)->get()->keyBy('id');
        $held = app(AssetHeldQuantities::class)->handle($assetIds);
        $parts = $partIds === [] ? [] : app(PartsForCheckout::class)->handle($partIds);

        $items = collect($purchase['lines'])->map(function (array $line) use ($assets, $held, $parts, $purchase) {
            $option = $line['item_type'] === CheckoutItem::TYPE_ASSET
                ? ($assets->has($line['id']) ? $this->assetOption($assets[$line['id']], $held[$line['id']] ?? 0) : null)
                : (isset($parts[$line['id']]) ? $this->partOption($parts[$line['id']]) : null);

            return $option ? [...$option, 'qty' => $line['qty'], 'purchase_request_id' => $purchase['id']] : null;
        })->filter()->values()->all();

        return [
            'ulid' => $purchase['ulid'],
            'pr_no' => $purchase['pr_no'],
            'borrower_user_id' => $purchase['requested_by'],
            'contract_id' => $purchase['contract_id'],
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> one asset for the line picker */
    private function assetOption(Asset $asset, int $held): array
    {
        return [
            'item_type' => CheckoutItem::TYPE_ASSET,
            'asset_id' => $asset->id,
            'asset_ulid' => $asset->ulid,
            'part_id' => null,
            'code' => $asset->asset_code,
            'name' => $asset->name,
            'detail' => collect([$asset->brand, $asset->model, $asset->serial_number])->filter()->implode(' · '),
            'unit' => $asset->unit,
            'lot' => (int) $asset->quantity > 1,
            'available' => max(0, (int) $asset->quantity - $held),
        ];
    }

    /** @return array<string, mixed> one part for the line picker */
    private function partOption(array $part): array
    {
        return [
            'item_type' => CheckoutItem::TYPE_PART,
            'asset_id' => null,
            'asset_ulid' => null,
            'part_id' => $part['id'],
            'code' => $part['code'],
            'name' => $part['name'],
            'detail' => $part['part_number'],
            'unit' => $part['unit'],
            'lot' => true,
            'available' => $part['qty_on_hand'],
        ];
    }

    /**
     * @param  LengthAwarePaginator  $page
     */
    private function linesPage($page): mixed
    {
        $items = $page->getCollection();
        $partIds = $items->where('item_type', CheckoutItem::TYPE_PART)->pluck('part_id')->all();
        $onHand = $partIds === [] || ! $this->modules->enabled('inventory') ? [] : array_map(fn (array $p) => $p['qty_on_hand'], app(PartsForCheckout::class)->handle($partIds));
        $purchases = $this->modules->enabled('inventory') ? app(PurchaseRequestLabels::class)->handle($items->pluck('purchase_request_id')->all()) : [];
        $items->loadMissing('asset:id,ulid');

        return $page->through(fn (CheckoutItem $item) => [
            ...RequestRow::item($item, $purchases, $onHand),
            'request' => $item->request->only(['ulid', 'request_no', 'status', 'borrower_name', 'requester_name']) + ['needed_by' => $item->request->needed_by?->toDateString()],
        ]);
    }

    /** Printed by whoever may see it, once approved (it is the hand-over paper). */
    private function authorizePrint(Request $request, CheckoutRequest $checkout): void
    {
        Gate::authorize('view', $checkout);
        abort_if(in_array($checkout->status, CheckoutRequest::UNPRINTABLE, true), 404);
    }

    /** @return array<string, mixed> */
    private function sheet(CheckoutRequest $checkout, TenantContext $context): array
    {
        $tenant = $context->tenant();
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);
        $checkout->load('items');
        $partLines = $checkout->items->where('item_type', CheckoutItem::TYPE_PART);
        $inventory = $this->modules->enabled('inventory') && $partLines->isNotEmpty();
        // The serials of the pieces as written when handed out, and the take-backs since (Inventory module).
        $serials = $inventory ? app(IssuedPartSerials::class)->handle($partLines->pluck('id')->all()) : [];

        return [
            'company' => $tenant ? CompanyProfile::of($tenant) : null,
            // The PDF service cannot sign in to fetch the logo, so it travels inside the page.
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'request' => $checkout,
            'items' => $checkout->items->whereNotIn('status', [CheckoutItem::STATUS_REJECTED, CheckoutItem::STATUS_CANCELLED])->values(),
            'partSerials' => $serials,
            // Brand and model of the parts, as they are now.
            'partInfo' => $inventory ? app(PartsForCheckout::class)->handle($partLines->pluck('part_id')->unique()->values()->all()) : [],
            // Each take-back after the hand-over makes a corrected issue of the papers.
            'revision' => collect($serials)->sum('revisions') + $checkout->items->where('item_type', CheckoutItem::TYPE_PART)
                ->filter(fn (CheckoutItem $item) => $item->qty_returned > 0 && ! isset($serials[$item->id]))->count(),
        ];
    }
}
