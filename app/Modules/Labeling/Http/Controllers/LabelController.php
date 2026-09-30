<?php

namespace App\Modules\Labeling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\AssetSummaries;
use App\Modules\Asset\Actions\CategoryNames;
use App\Modules\Asset\Actions\PaginateAssets;
use App\Modules\Asset\Actions\SearchAssets;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Models\User;
use App\Modules\Labeling\Actions\LastLabelPrints;
use App\Modules\Labeling\Actions\QrSvg;
use App\Modules\Labeling\Actions\RecordLabelPrint;
use App\Modules\Labeling\Support\LabelTemplates;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asset labels with a QR code that opens the asset's scan page (/a/{ulid}).
 */
class LabelController extends Controller
{
    public const PERMISSION = 'sticker.print';

    /** Most labels on one print page. */
    public const MAX_LABELS = 300;

    public function __construct(
        private ListCustomers $listCustomers,
        private Modules $modules,
    ) {}

    /**
     * Assets to choose from: the asset list's search, filters and sort, plus "printed" (yes/no).
     */
    public function index(Request $request, PaginateAssets $paginateAssets, LastLabelPrints $lastPrints, CategoryNames $categoryNames): Response
    {
        Gate::authorize(self::PERMISSION);

        $user = $request->user();
        $filters = SearchAssets::filtersFrom($request) + [
            'printed' => in_array($request->input('printed'), ['yes', 'no'], true) ? $request->input('printed') : null,
        ];
        $printed = $filters['printed'] ? $lastPrints->printedAssetIds() : [];

        $assets = $paginateAssets->handle(
            $user,
            $filters,
            onlyIds: $filters['printed'] === 'yes' ? $printed : null,
            exceptIds: $filters['printed'] === 'no' ? $printed : [],
        );
        $last = $lastPrints->handle(collect($assets->items())->pluck('id')->all());
        $customers = collect($this->customers(withTrashed: true))->pluck('name', 'id');

        return Inertia::render('Labeling/Index', [
            'assets' => $assets->through(fn (array $asset) => [
                ...collect($asset)->only(['ulid', 'asset_code', 'name', 'category', 'branch', 'serial_number', 'location'])->all(),
                'customer' => $customers[$asset['customer_id']] ?? null,
                'last_printed_at' => $last[$asset['id']] ?? null,
            ]),
            'filters' => $filters,
            'customers' => $this->customers(),
            'categories' => collect($categoryNames->handle())->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values(),
            'templates' => LabelTemplates::all(),
            'maxLabels' => self::MAX_LABELS,
        ]);
    }

    /**
     * The print page: ?assets=ulid,ulid,...&template=... It is opened in a new tab, so a bad link
     * is a 404 (redirecting "back" with errors would land on the same URL again and loop).
     */
    public function print(Request $request, QrSvg $qr, TenantContext $context): Response
    {
        Gate::authorize(self::PERMISSION);

        $data = [
            'assets' => array_values(array_filter(explode(',', (string) $request->input('assets')))),
            'template' => $request->input('template'),
        ];
        abort_if(Validator::make($data, [
            'assets' => ['required', 'array', 'min:1', 'max:'.self::MAX_LABELS],
            'assets.*' => ['string', 'size:26'],
            'template' => ['nullable', Rule::in(LabelTemplates::keys())],
        ])->fails(), 404);

        $customers = collect($this->customers(withTrashed: true))->pluck('name', 'id');
        $labels = collect($this->visibleAssets($request->user(), $data['assets']))
            ->map(fn (array $asset) => [
                ...collect($asset)->only(['ulid', 'asset_code', 'name', 'category', 'serial_number'])->all(),
                'customer' => $customers[$asset['customer_id']] ?? null,
                'qr' => $qr->handle(route('labeling.scan', $asset['ulid'])),
            ]);
        abort_if($labels->isEmpty(), 404);

        return Inertia::render('Labeling/Print', [
            'labels' => $labels->values(),
            'template' => $data['template'] ?? LabelTemplates::DEFAULT,
            'templates' => LabelTemplates::all(),
            'company' => $context->tenant()?->name,
        ]);
    }

    /**
     * Called when the print dialog is opened: logs the print.
     */
    public function record(Request $request, RecordLabelPrint $recordPrint): RedirectResponse
    {
        Gate::authorize(self::PERMISSION);

        $data = $request->validate([
            'assets' => ['required', 'array', 'min:1', 'max:'.self::MAX_LABELS],
            'assets.*' => ['string', 'size:26'],
            'template' => ['required', Rule::in(LabelTemplates::keys())],
        ]);

        $ids = collect($this->visibleAssets($request->user(), $data['assets']))->pluck('id')->all();
        $count = $recordPrint->handle($request->user(), $ids, $data['template']);

        return back()->with('success', __('labeling.printed', ['count' => $count]));
    }

    /**
     * Assets by ulid that the user may see (other tenants, other branches and deleted ones drop out).
     *
     * @param  list<string>  $ulids
     * @return list<array<string, mixed>> in the order of $ulids
     */
    private function visibleAssets(User $user, array $ulids): array
    {
        $ids = collect(app(AssetDetails::class)->handle($ulids, byUlid: true))->pluck('id')->all();
        $rows = collect(app(AssetSummaries::class)->handle($user, ['ids' => $ids]))->keyBy('ulid');

        return collect($ulids)->unique()->map(fn (string $ulid) => $rows->get($ulid))->filter()->values()->all();
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function customers(bool $withTrashed = false): array
    {
        return $this->modules->enabled('contract') ? $this->listCustomers->handle($withTrashed) : [];
    }
}
