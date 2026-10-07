<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\SwitchCompany;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * "เปลี่ยนบริษัท": log in as the person's own row of another company (SwitchCompany).
 */
class SwitchCompanyController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, SwitchCompany $switch): RedirectResponse
    {
        $account = $switch->handle($request->user(), $tenant);

        Auth::guard('web')->login($account);
        $request->session()->regenerate();

        return redirect()->to($this->dashboardOf($request, $tenant));
    }

    /**
     * On a company's own host ({sub}.{central domain}) the dashboard of the new company is on its
     * host; on a shared host it is the same address.
     */
    private function dashboardOf(Request $request, Tenant $tenant): string
    {
        $host = $request->getHost();

        foreach (config('tenancy.central_domains') as $central) {
            if (Str::endsWith($host, '.'.$central)) {
                $port = $request->getPort() && ! in_array($request->getPort(), [80, 443], true) ? ':'.$request->getPort() : '';

                return $request->getScheme()."://{$tenant->subdomain}.{$central}{$port}".route('dashboard', [], false);
            }
        }

        return route('dashboard');
    }
}
