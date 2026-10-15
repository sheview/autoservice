<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Actions\CreatePmReportShare;
use App\Modules\Maintenance\Actions\RevokePmReportShare;
use App\Modules\Maintenance\Models\PmReportShare;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Customers' links to a published PM report: made and revoked by who may share reports
 * (pm-reports.share) of that work order. The new link is flashed once ("share_url").
 */
class PmReportShareController extends Controller
{
    public function store(Request $request, PmVisit $visit, CreatePmReportShare $create): RedirectResponse
    {
        Gate::authorize('share', $visit);

        $data = $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'days' => ['required', 'integer', 'min:1', 'max:'.PmReportShare::MAX_DAYS],
            'allow_download' => ['boolean'],
            'follow_latest' => ['boolean'],
        ], [], __('maintenance.fields'));

        ['url' => $url] = $create->handle($visit, $data, $request->user());

        return back()->with('success', __('maintenance.shares.created'))->with('share_url', $url);
    }

    public function destroy(Request $request, PmVisit $visit, PmReportShare $share, RevokePmReportShare $revoke): RedirectResponse
    {
        Gate::authorize('share', $visit);

        $revoke->handle($share, $request->user());

        return back()->with('success', __('maintenance.shares.revoked'));
    }
}
