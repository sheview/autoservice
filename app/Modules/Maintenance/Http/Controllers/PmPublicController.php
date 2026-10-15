<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Actions\PmSignLinkByToken;
use App\Modules\Maintenance\Actions\PmSignSummary;
use App\Modules\Maintenance\Actions\SignPmVisitByCustomer;
use App\Modules\Maintenance\Models\PmSignature;
use App\Modules\Maintenance\Models\PmSignLink;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\PmNumber;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicLookupGuard;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PM pages for the customer, without signing in, on the company's own host or /t/{company code}/…:
 *
 *   pm-sign/{token}   sign a handed-in work order on their own phone (a sign link: once, until it
 *                     expires), with the satisfaction they tick — it locks the results
 *   pm-track/{token}  where a work order stands (the QR on the sheet): status and dates, no results
 *
 * A token is looked for in that company only; anything wrong reads the same "not found" and counts
 * towards the lookup guard.
 */
class PmPublicController extends Controller
{
    public function __construct(private TenantContext $context, private Modules $modules, private PmSignLinkByToken $linkByToken) {}

    public function signOnHost(PublicTenant $publicTenant, string $token): Response
    {
        return $this->showSign($publicTenant->resolve(), $token);
    }

    public function signOnPath(string $code, string $token): Response
    {
        return $this->showSign(CompanyCodes::tenant($code), $token);
    }

    public function storeSignOnHost(Request $request, PublicTenant $publicTenant, string $token, SignPmVisitByCustomer $sign): RedirectResponse
    {
        return $this->storeSign($request, $publicTenant->resolve(), $token, $sign);
    }

    public function storeSignOnPath(Request $request, string $code, string $token, SignPmVisitByCustomer $sign): RedirectResponse
    {
        return $this->storeSign($request, CompanyCodes::tenant($code), $token, $sign);
    }

    public function trackOnHost(PublicTenant $publicTenant, string $token): Response
    {
        return $this->track($publicTenant->resolve(), $token);
    }

    public function trackOnPath(string $code, string $token): Response
    {
        return $this->track(CompanyCodes::tenant($code), $token);
    }

    private function showSign(?Tenant $tenant, string $token): Response
    {
        $link = $this->link($tenant, $token);

        return Inertia::render('Maintenance/PublicSign', [
            'company' => $link ? $tenant->name : null,
            'work' => $link ? $this->context->run($tenant, fn () => [...app(PmSignSummary::class)->handle($link->visit), 'expires_at' => $link->expires_at->toIso8601String()]) : null,
            'levels' => PmSignature::SATISFACTION_LEVELS,
            'signed' => session('pm_signed', false),
        ]);
    }

    private function storeSign(Request $request, ?Tenant $tenant, string $token, SignPmVisitByCustomer $sign): RedirectResponse
    {
        $link = $this->link($tenant, $token);
        abort_if($link === null, 404);

        $data = $request->validate([
            'image' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:600000'],
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'satisfaction' => ['nullable', Rule::in(PmSignature::SATISFACTION_LEVELS)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => __('maintenance.fields.signer_name')] + __('maintenance.fields'));

        $this->context->run($tenant, fn () => $sign->handle($link->visit, $data, null, ['ip' => $request->ip(), 'user_agent' => $request->userAgent()], $link));

        return back()->with('pm_signed', true);
    }

    private function track(?Tenant $tenant, string $token): Response
    {
        $usable = $this->usable($tenant) && preg_match('/^[A-Za-z0-9]{40,64}$/', $token);
        $visit = $usable ? $this->context->run($tenant, fn () => PmVisit::query()->where('tracking_token', $token)->first()) : null;
        if ($visit === null) {
            PublicLookupGuard::missed(request()->ip(), $usable ? $tenant : null, 'pm-track');
        }

        return Inertia::render('Maintenance/PublicTrack', [
            'company' => $visit ? $tenant->name : null,
            'work' => $visit ? $this->context->run($tenant, fn () => [
                'number' => PmNumber::shown($visit->visit_no, $visit->tenant_id),
                'title' => $visit->displayTitle(),
                'status' => $visit->status,
                'scheduled_on' => ($visit->scheduled_on ?? $visit->due_on)?->toDateString(),
                'completed_at' => $visit->completed_at?->toIso8601String(),
                'locked_at' => $visit->locked_at?->toIso8601String(),
                'items' => $visit->items()->count(),
            ]) : null,
        ]);
    }

    private function link(?Tenant $tenant, string $token): ?PmSignLink
    {
        $link = $this->usable($tenant) ? $this->context->run($tenant, fn () => $this->linkByToken->handle($token)) : null;
        if ($link === null) {
            PublicLookupGuard::missed(request()->ip(), $this->usable($tenant) ? $tenant : null, 'pm-sign-link');
        }

        return $link;
    }

    private function usable(?Tenant $tenant): bool
    {
        return $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('maintenance', $tenant);
    }
}
