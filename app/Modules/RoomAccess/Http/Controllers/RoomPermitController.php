<?php

namespace App\Modules\RoomAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Actions\RenderPdf;
use App\Modules\Document\Exceptions\PdfUnavailable;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicLookupGuard;
use App\Modules\RoomAccess\Actions\IssueRoomAccessToken;
use App\Modules\RoomAccess\Actions\RequestByPermitToken;
use App\Modules\RoomAccess\Actions\RoomPermitSheet;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The permit to enter a room: as a PDF or a page to print (for whoever may see the request, once
 * approved), its QR link shown at the guard's counter without signing in (/room-permit/{token} on
 * the company's host, or /t/{company code}/room-permit/{token} on the shared one), and a new link
 * for the requester when the old one may have gone astray (the old one stops working).
 */
class RoomPermitController extends Controller
{
    /** A permit exists from approval on, also after the visit (for the record). */
    public const PRINTABLE = [RoomAccessRequest::STATUS_APPROVED, RoomAccessRequest::STATUS_INSIDE, RoomAccessRequest::STATUS_EXITED];

    public function __construct(private TenantContext $context, private Modules $modules) {}

    public function print(Request $request, RoomAccessRequest $roomRequest, RoomPermitSheet $sheet): View
    {
        $this->authorizePrint($roomRequest);

        return view('documents.room-permit', [...$sheet->handle($roomRequest), 'forBrowser' => true]);
    }

    public function pdf(Request $request, RoomAccessRequest $roomRequest, RoomPermitSheet $sheet, RenderPdf $renderPdf): HttpResponse|RedirectResponse
    {
        $this->authorizePrint($roomRequest);

        try {
            return $renderPdf->handle('documents.room-permit', $sheet->handle($roomRequest), "{$roomRequest->request_no}.pdf");
        } catch (PdfUnavailable) {
            return back()->with('error', __('document.unavailable'));
        }
    }

    /** A new permit link (the requester, or whoever may approve); the old one stops working. */
    public function renew(Request $request, RoomAccessRequest $roomRequest, IssueRoomAccessToken $issue): RedirectResponse
    {
        Gate::authorize('view', $roomRequest);
        abort_unless((int) $roomRequest->requester_id === $request->user()->id || $request->user()->can('room-access.approve'), 403);
        abort_unless(in_array($roomRequest->status, [RoomAccessRequest::STATUS_APPROVED, RoomAccessRequest::STATUS_INSIDE], true), 404);

        $issue->handle($roomRequest, RoomAccessToken::PERMIT, $request->user());
        activity()->performedOn($roomRequest)->causedBy($request->user())->event('room_access_permit_renewed')
            ->withProperties(['request_no' => $roomRequest->request_no])
            ->log(__('room_access.log.permit_renewed', ['no' => $roomRequest->request_no]));

        return back()->with('success', __('room_access.permit.renewed'));
    }

    public function onHost(PublicTenant $publicTenant, string $token, RequestByPermitToken $find, RoomPermitSheet $sheet): Response
    {
        return $this->counter($publicTenant->resolve(), $token, $find, $sheet);
    }

    public function onPath(string $code, string $token, RequestByPermitToken $find, RoomPermitSheet $sheet): Response
    {
        return $this->counter(CompanyCodes::tenant($code), $token, $find, $sheet);
    }

    /** The counter page: anything wrong reads the same "not found". */
    private function counter(?Tenant $tenant, string $token, RequestByPermitToken $find, RoomPermitSheet $sheet): Response
    {
        $usable = $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('room_access', $tenant);
        $permit = $this->context->run($usable ? $tenant : null, function () use ($token, $find, $sheet) {
            $row = $find->handle($token);

            return $row?->request ? $sheet->handle($row->request, public: true, scanned: $row) : null;
        });
        if ($permit === null) {
            PublicLookupGuard::missed(request()->ip(), $usable ? $tenant : null, 'room-permit');
        }

        return Inertia::render('RoomAccess/Permit', [
            'permit' => $permit === null ? null : [
                ...collect($permit)->except(['logo', 'qr', 'link'])->all(),
                'request' => [
                    ...$permit['request'],
                    'planned_start' => $permit['request']['planned_start']->toIso8601String(),
                    'planned_end' => $permit['request']['planned_end']->toIso8601String(),
                    'entered_at' => $permit['request']['entered_at']?->toIso8601String(),
                    'exited_at' => $permit['request']['exited_at']?->toIso8601String(),
                ],
                'approval' => $permit['approval'] ? ['name' => $permit['approval']['name'], 'at' => $permit['approval']['at']->toIso8601String()] : null,
                'acceptance' => $permit['acceptance'] ? [...$permit['acceptance'], 'at' => $permit['acceptance']['at']->toIso8601String()] : null,
                'expires_at' => $permit['expires_at']?->toIso8601String(),
            ],
        ]);
    }

    private function authorizePrint(RoomAccessRequest $roomRequest): void
    {
        Gate::authorize('view', $roomRequest);
        abort_unless(in_array($roomRequest->status, self::PRINTABLE, true), 404);
    }
}
