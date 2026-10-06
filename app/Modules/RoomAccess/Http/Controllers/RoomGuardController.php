<?php

namespace App\Modules\RoomAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicLookupGuard;
use App\Modules\RoomAccess\Actions\RecordRoomEntry;
use App\Modules\RoomAccess\Actions\RecordRoomExit;
use App\Modules\RoomAccess\Actions\RequestByPermitToken;
use App\Modules\RoomAccess\Actions\RoomPermitSheet;
use App\Modules\RoomAccess\Actions\RoomRulesForRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The guards' link of a request, without signing in (/room-guard/{token} on the company's host,
 * or /t/{code}/room-guard/{token}): the permit as at the counter, and buttons to record the team
 * going in and coming out, with the guard's name. One request only, while the link is valid;
 * anything wrong reads "not found".
 */
class RoomGuardController extends Controller
{
    public function __construct(private TenantContext $context, private Modules $modules, private RequestByPermitToken $find) {}

    public function showOnHost(PublicTenant $publicTenant, string $token, RoomPermitSheet $sheet, RoomRulesForRequest $rules): Response
    {
        return $this->show($publicTenant->resolve(), $token, $sheet, $rules);
    }

    public function showOnPath(string $code, string $token, RoomPermitSheet $sheet, RoomRulesForRequest $rules): Response
    {
        return $this->show(CompanyCodes::tenant($code), $token, $sheet, $rules);
    }

    public function recordOnHost(Request $request, PublicTenant $publicTenant, string $token, string $action, RecordRoomEntry $enter, RecordRoomExit $exit): RedirectResponse
    {
        return $this->record($request, $publicTenant->resolve(), $token, $action, $enter, $exit);
    }

    public function recordOnPath(Request $request, string $code, string $token, string $action, RecordRoomEntry $enter, RecordRoomExit $exit): RedirectResponse
    {
        return $this->record($request, CompanyCodes::tenant($code), $token, $action, $enter, $exit);
    }

    private function usable(?Tenant $tenant): bool
    {
        return $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('room_access', $tenant);
    }

    private function show(?Tenant $tenant, string $token, RoomPermitSheet $sheet, RoomRulesForRequest $rules): Response
    {
        $usable = $this->usable($tenant);
        $page = $this->context->run($usable ? $tenant : null, function () use ($token, $sheet, $rules) {
            $row = $this->find->handle($token, RoomAccessToken::GUARD);
            if ($row?->request === null) {
                return null;
            }
            $permit = $sheet->handle($row->request, public: true, scanned: $row);
            $room = $row->request->room;

            return [
                'permit' => [
                    ...collect($permit)->only(['company', 'valid', 'room', 'customer', 'people', 'items'])->all(),
                    'request' => [
                        ...collect($permit['request'])->only(['request_no', 'status', 'requester_name', 'purpose', 'schedule'])->all(),
                        'planned_start' => $permit['request']['planned_start']->toIso8601String(),
                        'planned_end' => $permit['request']['planned_end']->toIso8601String(),
                        'entered_at' => $permit['request']['entered_at']?->toIso8601String(),
                        'exited_at' => $permit['request']['exited_at']?->toIso8601String(),
                    ],
                ],
                // The rules to confirm with the team at the door, when the room asks for it.
                'rules' => $room?->accept_on_enter ? collect($rules->handle($room))->only(['summary', 'company_terms', 'version'])->all() : null,
                'usable' => $row->usable(),
            ];
        });
        if ($page === null) {
            PublicLookupGuard::missed(request()->ip(), $usable ? $tenant : null, 'room-guard');
        }

        return Inertia::render('RoomAccess/Guard', ['page' => $page]);
    }

    private function record(Request $request, ?Tenant $tenant, string $token, string $action, RecordRoomEntry $enter, RecordRoomExit $exit): RedirectResponse
    {
        abort_unless($this->usable($tenant) && in_array($action, ['enter', 'exit'], true), 404);
        $data = $request->validate([
            'guard_name' => ['required', 'string', 'max:255'],
            'accept' => ['nullable', 'boolean'],
        ], [], ['guard_name' => __('room_access.fields.guard_name')]);

        $this->context->run($tenant, function () use ($token, $action, $data, $request, $enter, $exit) {
            $row = $this->find->handle($token, RoomAccessToken::GUARD);
            abort_if($row === null || $row->request === null || ! $row->usable(), 404);

            $action === 'enter'
                ? $enter->handle($row->request, null, $data['guard_name'], ['accept' => (bool) ($data['accept'] ?? false), 'ip' => $request->ip(), 'user_agent' => $request->userAgent()])
                : $exit->handle($row->request, null, $data['guard_name']);
        });

        return back()->with('success', __("room_access.visit.{$action}ed_guard"));
    }
}
