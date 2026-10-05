<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Contract\Actions\ListSites;
use App\Modules\Labeling\Actions\QrSvg;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\RoomAccess\Models\RoomAccessApproval;
use App\Modules\RoomAccess\Models\RoomAccessItem;
use App\Modules\RoomAccess\Models\RoomAccessPerson;
use App\Modules\RoomAccess\Models\RoomAccessRequest;
use App\Modules\RoomAccess\Models\RoomAccessToken;
use App\Modules\RoomAccess\Models\RoomRuleAcceptance;
use App\Modules\RoomAccess\Support\IdNumber;
use App\Modules\Service\Actions\TicketLabels;
use App\Modules\Tenancy\Support\CompanyProfile;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * What the permit to enter a room shows, on paper (PDF / print) and at the guard's counter (its
 * QR link): the company, the room of which customer and site, when, who goes in, what equipment,
 * who approved and when, and the rules accepted (customer, room, version, when, by whom, the
 * lines). On the counter page ($public) entrants' phones and ID numbers are left out.
 */
class RoomPermitSheet
{
    public function __construct(private TenantContext $context, private Modules $modules) {}

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  RoomAccessToken|null  $scanned  on the counter page: the link scanned, which decides whether it is valid
     */
    public function handle(RoomAccessRequest $request, bool $public = false, ?RoomAccessToken $scanned = null): array
    {
        $tenant = $this->context->tenant();
        $request->loadMissing(['room', 'people', 'items', 'acceptances', 'approvals']);
        $room = $request->room;
        $logo = $public ? null : $tenant?->getFirstMedia(CompanyProfile::LOGO);
        $approval = $request->approvals->where('decision', RoomAccessApproval::APPROVED)->where('round', $request->round)->sortByDesc('decided_at')->first();
        $acceptance = $request->acceptances->where('context', RoomRuleAcceptance::CONTEXT_SUBMIT)->sortByDesc('accepted_at')->first();
        $token = $scanned ?? RoomAccessToken::query()->where('request_id', $request->id)->where('purpose', RoomAccessToken::PERMIT)
            ->whereNull('revoked_at')->latest('id')->first();
        $link = $token && $tenant ? PublicUrl::forTenant($tenant, '/room-permit/'.$token->token) : null;
        $site = $room?->site_id ? (collect(app(ListSites::class)->handle(withTrashed: true))->firstWhere('id', $room->site_id)['name'] ?? null) : null;

        return [
            'company' => $tenant ? CompanyProfile::of($tenant) : null,
            // The PDF service cannot sign in to fetch the logo, so it travels inside the page.
            'logo' => $logo ? 'data:'.$logo->mime_type.';base64,'.base64_encode(stream_get_contents($logo->stream())) : null,
            'request' => [
                ...$request->only(['request_no', 'status', 'requester_name', 'purpose']),
                'planned_start' => $request->planned_start,
                'planned_end' => $request->planned_end,
                'entered_at' => $request->entered_at,
                'exited_at' => $request->exited_at,
            ],
            'valid' => in_array($request->status, [RoomAccessRequest::STATUS_APPROVED, RoomAccessRequest::STATUS_INSIDE], true)
                && $token !== null && $token->usable(),
            'room' => ['name' => $room?->name, 'location' => $room?->location, 'site' => $site],
            'customer' => app(CustomerLabelNames::class)->handle()[$request->customer_id] ?? '-',
            'ticket' => $request->ticket_id && $this->modules->enabled('service')
                ? (app(TicketLabels::class)->handle([$request->ticket_id])[$request->ticket_id]['ticket_no'] ?? null) : null,
            'contract' => $request->contract_id && $this->modules->enabled('contract')
                ? (app(ContractLabels::class)->handle([$request->contract_id])[$request->contract_id]['contract_no'] ?? null) : null,
            'people' => $request->people->map(fn (RoomAccessPerson $p) => [
                'name' => $p->name,
                'company' => $p->company,
                'phone' => $public ? null : $p->phone,
                'id_number' => $public ? null : IdNumber::mask($p->id_number),
            ])->values()->all(),
            'show_ids' => ! $public && (bool) $room?->requires_id_number,
            'items' => $request->items->map(fn (RoomAccessItem $i) => $i->only(['name', 'serial_number', 'quantity', 'direction']))->values()->all(),
            'approval' => $approval ? ['name' => $approval->actor_name, 'at' => $approval->decided_at] : null,
            'acceptance' => $acceptance ? [
                'customer' => $acceptance->snapshot['customer'] ?? null,
                'room' => $acceptance->snapshot['room'] ?? null,
                'version' => $acceptance->version,
                'at' => $acceptance->accepted_at,
                'name' => $acceptance->accepted_by_name,
                'team' => $acceptance->on_behalf_of_team,
                'summary' => $acceptance->snapshot['summary'] ?? [],
                'company_terms' => $acceptance->snapshot['company_terms'] ?? [],
            ] : null,
            'link' => $link,
            'qr' => $link ? app(QrSvg::class)->handle($link) : null,
            'expires_at' => $token?->expires_at,
        ];
    }
}
