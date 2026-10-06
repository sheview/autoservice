<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\StartImpersonation;
use App\Modules\Platform\Actions\StopImpersonation;
use App\Modules\Platform\Support\Impersonation;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImpersonationController extends Controller
{
    public function index(Request $request, Impersonation $impersonation): Response
    {
        abort_unless($impersonation->active() || $impersonation->mayImpersonate($request->user()), 403);

        $search = $request->string('search')->trim()->value();

        $tenants = Tenant::query()
            ->where('is_platform', false)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('subdomain', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => [
                ...$tenant->only(['id', 'ulid', 'name', 'subdomain', 'company_code', 'status']),
                'subscription' => Subscription::of($tenant),
            ]);

        return Inertia::render('Platform/Impersonation/Index', [
            'tenants' => $tenants,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request, Tenant $tenant, StartImpersonation $start): RedirectResponse
    {
        $start->handle($request->user(), $tenant, $request->session());

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request, StopImpersonation $stop): RedirectResponse
    {
        $stop->handle($request->user(), $request->session());

        return redirect()->route('platform.impersonation.index');
    }
}
