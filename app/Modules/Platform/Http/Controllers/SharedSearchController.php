<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\SharedAssetSearch;
use App\Modules\Asset\Models\CheckoutItem;
use App\Modules\Inventory\Actions\SharedPartSearch;
use App\Modules\Platform\CrossTenant\SharedRequests;
use App\Modules\Platform\CrossTenant\ShareGateway;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Service\Actions\TicketsForCheckout;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parts and assets of other companies that share them with the user's company: seen, and asked
 * for on an issue/loan request made in that company (SharedRequests), for a ticket of ours.
 * Seeing needs the same view permission here; asking needs asset-checkouts.request (or create) here.
 */
class SharedSearchController extends Controller
{
    private const KINDS = ['parts' => 'parts.view', 'assets' => 'assets.view'];

    private const REQUEST = ['parts' => 'parts.request', 'assets' => 'assets.request'];

    public function __invoke(Request $request, ShareGateway $gateway, SharedPartSearch $parts, SharedAssetSearch $assets, SharedRequests $sharedRequests, TicketsForCheckout $tickets): Response
    {
        $user = $request->user();
        $kinds = array_keys(array_filter(self::KINDS, fn (string $ability) => $user->can($ability)));
        abort_if($kinds === [] || $user->customer_id !== null, 403);

        $kind = in_array($request->input('kind'), $kinds, true) ? $request->input('kind') : $kinds[0];
        $ability = self::KINDS[$kind];
        $companies = $gateway->targets($user, $ability);
        $search = $request->string('search')->trim()->value();
        $chosen = $request->integer('company') ? $companies->firstWhere('id', $request->integer('company')) : null;

        // Each company is read inside itself; a search with no company goes through all of them.
        $results = ($search === '' && ! $chosen) ? [] : ($chosen ? collect([$chosen]) : $companies)
            ->map(fn (Tenant $company) => [
                'company_id' => $company->id,
                'company' => $company->name,
                'rows' => $gateway->run($user, $company, $ability, fn (TenantShare $share) => $kind === 'parts'
                    ? $parts->handle($search)
                    : $assets->handle($search, array_map('intval', $share->branch_ids))),
            ])
            ->filter(fn (array $group) => $group['rows'] !== [])
            ->values()
            ->all();

        $mayAsk = $this->mayAsk($request);

        return Inertia::render('Platform/SharedSearch', [
            'filters' => ['kind' => $kind, 'company' => $chosen?->id, 'search' => $search, 'ticket' => $request->integer('ticket') ?: null],
            'kinds' => $kinds,
            'companies' => $companies->map(fn (Tenant $company) => $company->only(['id', 'name']))->values(),
            'results' => $results,
            // The companies that let us ask for this kind of thing.
            'requestable' => $mayAsk ? $gateway->targets($user, self::REQUEST[$kind])->pluck('id')->values() : [],
            'myRequests' => $sharedRequests->madeBy($user),
            // Opened from a ticket (?ticket=id): the request is for it.
            'presetTicket' => $mayAsk && $request->integer('ticket') ? ($tickets->handle($user, [$request->integer('ticket')])[$request->integer('ticket')] ?? null) : null,
        ]);
    }

    /** Asks company `company` for the items, for one of our tickets. */
    public function store(Request $request, ShareGateway $gateway, SharedRequests $sharedRequests, TicketsForCheckout $tickets): RedirectResponse
    {
        abort_unless($this->mayAsk($request), 403);
        $user = $request->user();

        $data = $request->validate([
            'company' => ['required', 'integer'],
            'ticket_id' => ['nullable', 'integer'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'needed_by' => ['nullable', 'date'],
            'borrower_phone' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.item_type' => ['required', Rule::in([CheckoutItem::TYPE_ASSET, CheckoutItem::TYPE_PART])],
            'items.*.id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.checkout_type' => ['nullable', Rule::in([CheckoutItem::ISSUE, CheckoutItem::LOAN])],
            'items.*.due_return_date' => ['nullable', 'date', 'after_or_equal:today'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ], attributes: __('platform.shares.fields'));

        $target = Tenant::query()->where('is_platform', false)->find($data['company']);
        abort_if($target === null, 404);
        $ticket = null;
        if (! empty($data['ticket_id'])) {
            $ticket = $tickets->handle($user, [(int) $data['ticket_id']])[(int) $data['ticket_id']] ?? null;
            if ($ticket === null) {
                throw ValidationException::withMessages(['ticket_id' => __('platform.shares.ticket_unknown')]);
            }
        }

        $link = $sharedRequests->request($user, $target, $ticket ? ['id' => $ticket['id'], 'ticket_no' => $ticket['ticket_no']] : null, [
            'purpose' => $data['purpose'] ?? null,
            'needed_by' => $data['needed_by'] ?? null,
            'borrower_phone' => $data['borrower_phone'] ?? null,
            'items' => array_map(fn (array $item) => [
                'item_type' => $item['item_type'],
                'asset_id' => $item['item_type'] === CheckoutItem::TYPE_ASSET ? (int) $item['id'] : null,
                'part_id' => $item['item_type'] === CheckoutItem::TYPE_PART ? (int) $item['id'] : null,
                'qty' => (int) $item['qty'],
                'checkout_type' => $item['checkout_type'] ?? CheckoutItem::ISSUE,
                'due_return_date' => $item['due_return_date'] ?? null,
                'note' => $item['note'] ?? null,
            ], $data['items']),
        ]);

        return back()->with('success', __('platform.shares.requested', ['no' => $link->target_label, 'company' => $target->name]));
    }

    /** Asking others for things takes the right to ask for them here. */
    private function mayAsk(Request $request): bool
    {
        $user = $request->user();

        return $user->customer_id === null && ($user->can('asset-checkouts.request') || $user->can('asset-checkouts.create'));
    }
}
