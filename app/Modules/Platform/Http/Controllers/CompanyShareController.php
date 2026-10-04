<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\DecideTenantShare;
use App\Modules\Platform\Models\TenantShare;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A company's admin (company.manage) sees what it shares with other companies and what they
 * share with it, accepts shares of its own data and revokes either kind.
 */
class CompanyShareController extends Controller
{
    public const PERMISSION = 'company.manage';

    public function index(TenantContext $context): Response
    {
        Gate::authorize(self::PERMISSION);
        $id = $this->company($context);

        $branches = Branch::pluck('name', 'id'); // ours: the branches of our data that are shared
        $row = fn (TenantShare $share, string $other) => [
            'people_count' => count($share->user_ids),
            'branches' => $other === 'fromTenant' ? collect($share->branch_ids)->map(fn ($id) => $branches[$id] ?? null)->filter()->values() : [],
            ...$share->only(['id', 'abilities', 'roles', 'status', 'reason', 'granted_by_name', 'accepted_by_name', 'revoked_by_name']),
            'company' => $share->{$other}?->name,
            'expires_on' => $share->expires_on?->toDateString(),
            'accepted_at' => $share->accepted_at?->toIso8601String(),
        ];

        return Inertia::render('Platform/CompanyShares', [
            // Others may see our data
            'incoming' => TenantShare::with('fromTenant')->where('to_tenant_id', $id)->orderByDesc('updated_at')->get()
                ->map(fn (TenantShare $share) => $row($share, 'fromTenant')),
            // We may see theirs
            'outgoing' => TenantShare::with('toTenant')->where('from_tenant_id', $id)->orderByDesc('updated_at')->get()
                ->map(fn (TenantShare $share) => $row($share, 'toTenant')),
        ]);
    }

    public function decide(Request $request, TenantShare $share, TenantContext $context, DecideTenantShare $decide): RedirectResponse
    {
        Gate::authorize(self::PERMISSION);
        $id = $this->company($context);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'revoke'])]]);

        // Accepting is for the company whose data is shared; revoking for either side.
        abort_unless($share->to_tenant_id === $id || ($data['decision'] === 'revoke' && $share->from_tenant_id === $id), 404);

        $decide->handle($share, $data['decision'], $request->user());

        return back()->with('success', __($data['decision'] === 'accept' ? 'platform.shares.accepted' : 'platform.shares.revoked'));
    }

    private function company(TenantContext $context): int
    {
        $tenant = $context->tenant();
        abort_if($tenant === null || $tenant->is_platform, 404);

        return $tenant->id;
    }
}
