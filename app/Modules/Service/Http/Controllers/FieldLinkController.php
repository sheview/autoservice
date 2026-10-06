<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicLookupGuard;
use App\Modules\Service\Actions\FieldLinkByToken;
use App\Modules\Service\Actions\RepairPresetList;
use App\Modules\Service\Actions\SubmitFieldReport;
use App\Modules\Service\Actions\TicketSheet;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Models\TicketFieldLink;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A ticket's link, without an account, from a phone or an iPad (/job/{token} on the company's host,
 * or /t/{code}/job/{token}): the job, the form to send the work back (or only the customer's
 * signature), and the job sheet to print for signing on paper. One ticket only, while the link
 * is valid; anything wrong reads "not found".
 */
class FieldLinkController extends Controller
{
    public const MAX_PHOTOS = 6;

    public const PHOTO_KB = 5120;

    public function __construct(private TenantContext $context, private Modules $modules, private FieldLinkByToken $find) {}

    public function showOnHost(PublicTenant $publicTenant, string $token, TicketSheet $sheet, RepairPresetList $presets): Response
    {
        return $this->show($publicTenant->resolve(), $token, $sheet, $presets);
    }

    public function showOnPath(string $code, string $token, TicketSheet $sheet, RepairPresetList $presets): Response
    {
        return $this->show(CompanyCodes::tenant($code), $token, $sheet, $presets);
    }

    public function submitOnHost(Request $request, PublicTenant $publicTenant, string $token, SubmitFieldReport $submit): RedirectResponse
    {
        return $this->submit($request, $publicTenant->resolve(), $token, $submit);
    }

    public function submitOnPath(Request $request, string $code, string $token, SubmitFieldReport $submit): RedirectResponse
    {
        return $this->submit($request, CompanyCodes::tenant($code), $token, $submit);
    }

    public function printOnHost(PublicTenant $publicTenant, string $token, TicketSheet $sheet): Response
    {
        return $this->print($publicTenant->resolve(), $token, $sheet);
    }

    public function printOnPath(string $code, string $token, TicketSheet $sheet): Response
    {
        return $this->print(CompanyCodes::tenant($code), $token, $sheet);
    }

    private function usable(?Tenant $tenant): bool
    {
        return $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('service', $tenant);
    }

    private function show(?Tenant $tenant, string $token, TicketSheet $sheet, RepairPresetList $presets): Response
    {
        $usable = $this->usable($tenant);
        $page = $this->context->run($usable ? $tenant : null, function () use ($token, $sheet, $presets) {
            $link = $this->find->handle($token);
            if ($link?->ticket === null) {
                return null;
            }
            $job = $sheet->handle($link->ticket, null);

            return [
                'company' => $job['company'],
                'link' => [
                    ...$link->only(['mode', 'holder_name', 'holder_company']),
                    'usable' => $link->usable(),
                    'expires_at' => $link->expires_at->toIso8601String(),
                    'submitted_at' => $link->submitted_at?->toIso8601String(),
                ],
                'ticket' => collect($job['ticket'])->only(['ticket_no', 'title', 'description', 'status', 'customer', 'contact_name', 'contact_phone', 'device', 'branch', 'report'])->all(),
                'signature' => $job['signature'] ? ['signer' => $job['signature']['signer'], 'signed_at' => $job['signature']['signed_at']] : null,
                'presets' => $link->mode === TicketFieldLink::MODE_WORK ? $presets->handle() : null,
            ];
        });
        if ($page === null) {
            PublicLookupGuard::missed(request()->ip(), $usable ? $tenant : null, 'field-link');
        }

        return Inertia::render('Service/FieldLink', ['page' => $page, 'limits' => ['photos' => self::MAX_PHOTOS, 'photo_kb' => self::PHOTO_KB]]);
    }

    private function submit(Request $request, ?Tenant $tenant, string $token, SubmitFieldReport $submit): RedirectResponse
    {
        abort_unless($this->usable($tenant), 404);
        $data = $request->validate([
            'symptoms' => ['array', 'max:20'],
            'symptoms.*' => ['string', 'max:100'],
            'solutions' => ['array', 'max:20'],
            'solutions.*' => ['string', 'max:100'],
            'parts' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'signer_name' => ['nullable', 'string', 'max:255'],
            // A PNG drawn on the screen, as a data URL, at most about 300 KB.
            'signature' => ['nullable', 'string', 'starts_with:data:image/png;base64,', 'max:400000'],
            'before' => ['array', 'max:'.self::MAX_PHOTOS],
            'before.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PHOTO_KB],
            'after' => ['array', 'max:'.self::MAX_PHOTOS],
            'after.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PHOTO_KB],
            // A photo of the printed job sheet signed on paper.
            'signed_sheet' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PHOTO_KB],
        ], [], __('service.field_links.fields'));

        $this->context->run($tenant, function () use ($token, $data, $request, $submit) {
            $link = $this->find->handle($token);
            abort_if($link === null, 404);
            $submit->handle($link, $data, [
                'before' => $request->file('before', []),
                'after' => $request->file('after', []),
                'signed_sheet' => $request->file('signed_sheet'),
            ]);
        });

        return back()->with('success', __('service.field_links.sent'));
    }

    /** The job sheet to print and have the customer sign on paper (as the helpdesk prints it). */
    private function print(?Tenant $tenant, string $token, TicketSheet $sheet): Response
    {
        $usable = $this->usable($tenant);
        $props = $this->context->run($usable ? $tenant : null, function () use ($token, $sheet) {
            $link = $this->find->handle($token);

            return $link?->ticket && $link->usable() ? $sheet->handle($link->ticket, null) : null;
        });
        abort_if($props === null, 404);

        return Inertia::render('Service/Tickets/Print', [...$props, 'publicView' => true]);
    }
}
