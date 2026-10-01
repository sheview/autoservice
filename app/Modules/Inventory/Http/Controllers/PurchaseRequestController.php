<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\ContractOptions;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Document\Http\Concerns\ServesAttachments;
use App\Modules\Document\Support\Attachments;
use App\Modules\Inventory\Actions\MovePurchaseRequest;
use App\Modules\Inventory\Actions\SavePurchaseRequest;
use App\Modules\Inventory\Actions\SearchPurchaseRequests;
use App\Modules\Inventory\Http\Requests\PurchaseRequestRequest;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Policies\PurchaseRequestPolicy;
use App\Modules\Inventory\Support\PurchaseRequestRow;
use App\Modules\Inventory\Support\PurchaseWorkflow;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\Money;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Purchase requests: asked for, approved, ordered, received (PurchaseWorkflow), printed for
 * signatures, with quotations attached.
 */
class PurchaseRequestController extends Controller
{
    use ServesAttachments;

    public function index(Request $request, SearchPurchaseRequests $search): Response
    {
        Gate::authorize('viewAny', PurchaseRequest::class);

        $filters = SearchPurchaseRequests::filtersFrom($request);

        return Inertia::render('Inventory/PurchaseRequests/Index', [
            'requests' => $search->handle($request->user(), $filters)->paginate(20)->withQueryString()
                ->through(fn (PurchaseRequest $pr) => PurchaseRequestRow::of($pr)),
            'filters' => $filters,
            'statuses' => PurchaseRequest::STATUSES,
            'can' => ['create' => $request->user()->can('create', PurchaseRequest::class), 'viewAll' => PurchaseRequestPolicy::seesEveryone($request->user())],
        ]);
    }

    /** ?item= starts the request from what was searched for on the issue/loan page. */
    public function create(Request $request, Modules $modules): Response
    {
        Gate::authorize('create', PurchaseRequest::class);

        return Inertia::render('Inventory/PurchaseRequests/Form', [
            'request' => null,
            'attachments' => [],
            'item' => $request->string('item')->trim()->limit(255, '')->value(),
            'maxLinks' => PurchaseRequest::MAX_LINKS,
            'contracts' => $modules->enabled('contract') ? app(ContractOptions::class)->handle() : [],
        ]);
    }

    public function store(PurchaseRequestRequest $request, SavePurchaseRequest $save): RedirectResponse
    {
        $pr = $save->handle(null, $request->requestData(), $request->user(), $request->attachments());

        return redirect()->route('inventory.purchase-requests.show', $pr)->with('success', __('inventory.purchase_requests.created', ['no' => $pr->pr_no]));
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest, Modules $modules): Response
    {
        Gate::authorize('view', $purchaseRequest);
        $user = $request->user();

        return Inertia::render('Inventory/PurchaseRequests/Show', [
            'request' => PurchaseRequestRow::of($purchaseRequest),
            // The project it is for: number and title.
            'contract' => $purchaseRequest->contract_id && $modules->enabled('contract')
                ? app(ContractLabels::class)->handle([$purchaseRequest->contract_id])[$purchaseRequest->contract_id] ?? null
                : null,
            'attachments' => $this->attachmentList($purchaseRequest),
            // Moves the user may make now.
            'actions' => collect(array_keys(PurchaseWorkflow::ACTIONS))->filter(fn (string $action) => $user->can('move', [$purchaseRequest, $action]))->values(),
            'needsNote' => PurchaseWorkflow::NEEDS_NOTE,
            'can' => [
                'update' => $user->can('update', $purchaseRequest),
                'attach' => $this->canAttach($request, $purchaseRequest),
                // Once received: register what arrived as assets.
                'createAsset' => $purchaseRequest->status === PurchaseRequest::STATUS_RECEIVED && $modules->enabled('asset') && $user->can('assets.create'),
            ],
        ]);
    }

    public function edit(PurchaseRequest $purchaseRequest, Modules $modules): Response
    {
        Gate::authorize('update', $purchaseRequest);

        return Inertia::render('Inventory/PurchaseRequests/Form', [
            'request' => [...PurchaseRequestRow::of($purchaseRequest), 'unit_price' => Money::toBaht($purchaseRequest->unit_price)],
            'attachments' => $this->attachmentList($purchaseRequest),
            'item' => '',
            'maxLinks' => PurchaseRequest::MAX_LINKS,
            'contracts' => $modules->enabled('contract') ? app(ContractOptions::class)->handle($purchaseRequest->contract_id) : [],
        ]);
    }

    public function update(PurchaseRequestRequest $request, PurchaseRequest $purchaseRequest, SavePurchaseRequest $save): RedirectResponse
    {
        $save->handle($purchaseRequest, $request->requestData(), $request->user(), $request->attachments());

        return redirect()->route('inventory.purchase-requests.show', $purchaseRequest)->with('success', __('inventory.purchase_requests.updated'));
    }

    public function move(Request $request, PurchaseRequest $purchaseRequest, MovePurchaseRequest $move): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(PurchaseWorkflow::ACTIONS))],
            'note' => ['nullable', 'string', 'max:2000'],
        ], attributes: __('inventory.purchase_requests.fields'));
        Gate::authorize('move', [$purchaseRequest, $data['action']]);

        $move->handle($purchaseRequest, $data['action'], $request->user(), $data['note'] ?? null);

        return back()->with('success', __('inventory.purchase_requests.moved.'.$data['action'], ['no' => $purchaseRequest->pr_no]));
    }

    /** The request as an A4 page for the browser to print. */
    public function print(PurchaseRequest $purchaseRequest, TenantContext $context): View
    {
        Gate::authorize('view', $purchaseRequest);

        return view('documents.purchase-request', [...$this->sheet($purchaseRequest, $context), 'forBrowser' => true]);
    }

    public function pdf(PurchaseRequest $purchaseRequest, TenantContext $context, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        Gate::authorize('view', $purchaseRequest);

        try {
            return $renderPdf->handle('documents.purchase-request', $this->sheet($purchaseRequest, $context), "{$purchaseRequest->pr_no}.pdf");
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    public function storeAttachment(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        abort_unless($this->canAttach($request, $purchaseRequest), 403);

        return $this->storeAttachments($request, $purchaseRequest);
    }

    public function attachment(PurchaseRequest $purchaseRequest, int $attachment): StreamedResponse
    {
        Gate::authorize('view', $purchaseRequest);

        return $this->showAttachment($purchaseRequest, $attachment);
    }

    public function removeAttachment(Request $request, PurchaseRequest $purchaseRequest, int $attachment): RedirectResponse
    {
        abort_unless($this->canAttach($request, $purchaseRequest), 403);

        return $this->destroyAttachment($purchaseRequest, $attachment);
    }

    /** Quotations: by whoever may change it (while it waits), or by approvers and buyers until it is closed. */
    private function canAttach(Request $request, PurchaseRequest $pr): bool
    {
        $user = $request->user();

        return $user->can('update', $pr)
            || (in_array($pr->status, PurchaseRequest::OPEN_STATUSES, true) && app(PurchaseRequestPolicy::class)->handles($user, $pr));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachmentList(PurchaseRequest $pr): array
    {
        return Attachments::list($pr, $pr->attachmentCollection(), fn (int $id) => route('inventory.purchase-requests.attachments.show', [$pr, $id]));
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(PurchaseRequest $pr, TenantContext $context): array
    {
        $tenant = $context->tenant();
        $logo = $tenant?->getFirstMedia(CompanyProfile::LOGO);

        return [
            'company' => $tenant ? CompanyProfile::of($tenant) : null,
            // The PDF service cannot sign in to fetch the logo, so it travels inside the page.
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'request' => $pr,
            'row' => PurchaseRequestRow::of($pr),
        ];
    }
}
