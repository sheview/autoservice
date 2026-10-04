<?php

namespace App\Modules\Contract\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contract\Actions\DeleteSite;
use App\Modules\Contract\Actions\SaveSite;
use App\Modules\Contract\Http\Requests\SiteRequest;
use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Models\CustomerSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The sites of a customer, kept on the customer's edit page (customers.update).
 */
class CustomerSiteController extends Controller
{
    public function store(SiteRequest $request, Customer $customer, SaveSite $saveSite): RedirectResponse
    {
        $saveSite->handle($customer, null, $request->validated());

        return back()->with('success', __('contract.sites.created'));
    }

    public function update(SiteRequest $request, Customer $customer, CustomerSite $site, SaveSite $saveSite): RedirectResponse
    {
        abort_unless($site->customer_id === $customer->id, 404);
        $saveSite->handle($customer, $site, $request->validated());

        return back()->with('success', __('contract.sites.updated'));
    }

    public function destroy(Customer $customer, CustomerSite $site, DeleteSite $deleteSite): RedirectResponse
    {
        Gate::authorize('update', $customer);
        abort_unless($site->customer_id === $customer->id, 404);
        $deleteSite->handle($site);

        return back()->with('success', __('contract.sites.deleted'));
    }
}
