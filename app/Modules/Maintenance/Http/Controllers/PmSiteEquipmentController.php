<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\AssetDetails;
use App\Modules\Asset\Actions\CatalogOptions;
use App\Modules\Asset\Actions\CategoryOptions;
use App\Modules\Asset\Actions\FindCustomerAssets;
use App\Modules\Maintenance\Actions\AddPmVisitItems;
use App\Modules\Maintenance\Actions\AddSiteEquipment;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\PmTemplateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Equipment found on site that the work order does not have: looked up in the customer's register by
 * what is read off it (a scan or typed), put on the work order when it is there, else registered right
 * here with a short form (AddSiteEquipment). Who arranges the work order, or who does the work.
 */
class PmSiteEquipmentController extends Controller
{
    /** What the short form offers: kinds, brands and models, forms, and the equipment already on it to copy from. */
    public function options(PmVisit $visit, CategoryOptions $categories, CatalogOptions $catalog, AssetDetails $assetDetails): JsonResponse
    {
        $this->authorizeWork($visit);
        $resolver = PmTemplateResolver::load($visit->pm_plan_id);
        $categoryList = $categories->handle();
        $assets = $assetDetails->handle($visit->items()->pluck('asset_id')->all());

        return response()->json([
            'categories' => $categoryList,
            'catalog' => $catalog->handle(),
            'templates' => $resolver->options(),
            // the form a new piece of each kind gets when none is picked
            'categoryTemplates' => (object) collect($categoryList)
                ->mapWithKeys(fn (array $category) => [$category['id'] => $resolver->for(['id' => 0, 'category_id' => $category['id']])?->code])
                ->filter()->all(),
            // "the same as": the kinds, brands, models and places on the work order, once each
            'copyFrom' => collect($assets)
                ->map(fn (array $asset) => collect($asset)->only(['asset_code', 'name', 'category_id', 'brand', 'model', 'location', 'floor', 'room'])->all())
                ->unique(fn (array $asset) => implode('|', [$asset['category_id'], $asset['brand'], $asset['model'], $asset['location'], $asset['floor'], $asset['room']]))
                ->values(),
            'hasPlan' => $visit->pm_plan_id !== null || $visit->contract_id !== null,
        ]);
    }

    /** The customer's equipment that matches what was scanned or typed, not on the work order yet. */
    public function search(Request $request, PmVisit $visit, FindCustomerAssets $find): JsonResponse
    {
        $this->authorizeWork($visit);
        $data = $request->validate(['q' => ['required', 'string', 'max:255']]);

        return response()->json([
            'found' => $find->handle($visit->customer_id, $request->user(), $data['q'], $visit->items()->pluck('asset_id')->all()),
        ]);
    }

    /** Equipment of the customer's register put on the work order. */
    public function existing(Request $request, PmVisit $visit, AddPmVisitItems $addItems, FindCustomerAssets $find): RedirectResponse
    {
        $this->authorizeWork($visit);
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'max:50'],
            'asset_ids.*' => ['integer', 'distinct'],
        ]);
        // Only what the search would have shown them: equipment they may not see is "not found".
        $visible = $find->handle($visit->customer_id, $request->user(), ids: array_map('intval', $data['asset_ids']));
        if (count($visible) !== count($data['asset_ids'])) {
            return back()->withErrors(['asset_ids' => __('maintenance.visits.asset_not_found')]);
        }

        $added = $addItems->handle($visit, array_map('intval', $data['asset_ids']), $request->user());

        return back()->with('success', __('maintenance.visits.items_added', ['count' => $added]));
    }

    /** New equipment registered from the work order and put on it. */
    public function store(Request $request, PmVisit $visit, AddSiteEquipment $add, AssetDetails $assetDetails): RedirectResponse
    {
        $this->authorizeWork($visit);
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'name' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'property_no' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'ip'],
            'location' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:50'],
            'room' => ['nullable', 'string', 'max:100'],
            'used_by' => ['nullable', 'string', 'max:255'],
            'template_code' => ['nullable', 'string', 'max:40'],
            'add_to_plan' => ['boolean'],
            'photo' => ['nullable', 'image', 'max:10240'],
        ], [], [
            ...collect(__('asset.columns'))->only(['name', 'brand', 'model', 'property_no', 'location', 'floor', 'room', 'ip_address', 'used_by'])->all(),
            'category_id' => __('asset.columns.category'),
            'serial_number' => __('asset.columns.serial_number'),
            'template_code' => __('ui.pm_visits.template'),
        ]);

        $item = $add->handle($visit, collect($data)->except('photo')->all(), $request->user(), $request->file('photo'));
        $code = $assetDetails->handle([$item->asset_id])[$item->asset_id]['asset_code'] ?? '';

        return back()->with('success', __('maintenance.visits.site_equipment_added', ['code' => $code]));
    }

    private function authorizeWork(PmVisit $visit): void
    {
        abort_unless(Gate::any(['update', 'perform'], $visit), 403);
    }
}
