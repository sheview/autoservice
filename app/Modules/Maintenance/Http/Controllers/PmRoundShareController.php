<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Actions\CreatePmRoundShare;
use App\Modules\Maintenance\Actions\RevokePmRoundShare;
use App\Modules\Maintenance\Models\PmPlan;
use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Models\PmRoundShare;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Customers' links to a round report (PM Reports page): made and revoked by staff who may share
 * PM reports. The new link is flashed once ("share_url").
 */
class PmRoundShareController extends Controller
{
    public function store(Request $request, CreatePmRoundShare $create): RedirectResponse
    {
        $this->authorizeStaff($request);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
            'plan_id' => ['nullable', 'integer', Rule::exists('pm_plans', 'id')->whereNull('deleted_at')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'days' => ['required', 'integer', 'min:1', 'max:'.PmReportShare::MAX_DAYS],
            'allow_download' => ['boolean'],
        ], [], __('maintenance.fields'));
        if (isset($data['plan_id'])) {
            Gate::authorize('view', PmPlan::findOrFail($data['plan_id']));
        }

        ['url' => $url] = $create->handle($data, $request->user());

        return back()->with('success', __('maintenance.shares.created'))->with('share_url', $url);
    }

    public function destroy(Request $request, PmRoundShare $roundShare, RevokePmRoundShare $revoke): RedirectResponse
    {
        $this->authorizeStaff($request);

        $revoke->handle($roundShare, $request->user());

        return back()->with('success', __('maintenance.shares.revoked'));
    }

    private function authorizeStaff(Request $request): void
    {
        Gate::authorize('pm-reports.share');
        abort_if($request->user()->customer_id !== null, 403);
    }
}
