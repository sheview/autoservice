<?php

namespace App\Modules\RoomAccess\Actions;

use App\Modules\Contract\Actions\ContractLabels;
use App\Modules\Contract\Actions\CustomerLabelNames;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Modules;
use App\Modules\RoomAccess\Models\RoomAccessPerson;
use App\Modules\RoomAccess\Models\RoomAccessVisit;
use App\Modules\Service\Actions\TicketLabels;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Report "who went into which room": each visit in a period (by the time they went in), with the
 * room, customer, requester, the people listed, the purpose and work done, who recorded going in
 * and out, and the ticket / MA contract. Only requests the user may see (room-access.view scope);
 * never phone or ID numbers. Filtered by room, customer, contract and a search.
 */
class RoomVisitReport
{
    public function __construct(private Modules $modules) {}

    /**
     * @param  array{search?: string, room?: string|null, customer_id?: int|null, contract_id?: int|null, direction?: string}  $filters
     * @return LengthAwarePaginator|list<array<string, mixed>>
     */
    public function handle(User $user, CarbonInterface $from, CarbonInterface $to, array $filters, ?int $perPage = null): LengthAwarePaginator|array
    {
        $search = (string) ($filters['search'] ?? '');
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query = RoomAccessVisit::query()
            ->whereBetween('entered_at', [$from, $to])
            ->whereHas('request', fn (Builder $r) => SearchRoomAccessRequests::visibleTo($r, $user)
                ->when($filters['room'] ?? null, fn (Builder $q, string $ulid) => $q->whereHas('room', fn (Builder $room) => $room->where('ulid', $ulid)))
                ->when($filters['customer_id'] ?? null, fn (Builder $q, int $id) => $q->where('customer_id', $id))
                ->when($filters['contract_id'] ?? null, fn (Builder $q, int $id) => $q->where('contract_id', $id))
                ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('request_no', 'ilike', $like)->orWhere('purpose', 'ilike', $like)->orWhere('requester_name', 'ilike', $like)
                    ->orWhere('work_summary', 'ilike', $like)
                    ->orWhereHas('people', fn (Builder $p) => $p->where('name', 'ilike', $like)))))
            ->with(['request.room:id,name', 'request.people:id,request_id,name,company,position'])
            ->orderBy('entered_at', ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id');

        if ($perPage !== null) {
            $page = $query->paginate($perPage)->withQueryString();
            $page->setCollection(collect($this->rows($page->getCollection()->all())));

            return $page;
        }

        return $this->rows($query->limit(5000)->get()->all());
    }

    /**
     * @param  list<RoomAccessVisit>  $visits
     * @return list<array<string, mixed>>
     */
    private function rows(array $visits): array
    {
        $requests = collect($visits)->map->request;
        $tickets = $this->modules->enabled('service') ? app(TicketLabels::class)->handle($requests->pluck('ticket_id')->filter()->unique()->values()->all()) : [];
        $contracts = $this->modules->enabled('contract') ? app(ContractLabels::class)->handle($requests->pluck('contract_id')->filter()->unique()->values()->all()) : [];
        $customers = $visits === [] ? [] : app(CustomerLabelNames::class)->handle();

        return array_map(function (RoomAccessVisit $visit) use ($tickets, $contracts, $customers) {
            $request = $visit->request;

            return [
                'id' => $visit->id,
                'entered_at' => $visit->entered_at->toIso8601String(),
                'exited_at' => $visit->exited_at?->toIso8601String(),
                'minutes' => $visit->exited_at ? (int) $visit->entered_at->diffInMinutes($visit->exited_at) : null,
                'entered_by_name' => $visit->entered_by_name,
                'exited_by_name' => $visit->exited_by_name,
                'ulid' => $request->ulid,
                'request_no' => $request->request_no,
                'room' => $request->room?->name,
                'customer' => $customers[$request->customer_id] ?? '-',
                'requester_name' => $request->requester_name,
                'people' => $request->people->map(fn (RoomAccessPerson $p) => $p->company ? "{$p->name} ({$p->company})" : $p->name)->values()->all(),
                'purpose' => $request->purpose,
                'work_summary' => $request->work_summary,
                'ticket' => $request->ticket_id && isset($tickets[$request->ticket_id]) ? $tickets[$request->ticket_id]['ticket_no'] : null,
                'contract' => $request->contract_id && isset($contracts[$request->contract_id]) ? $contracts[$request->contract_id]['contract_no'] : null,
            ];
        }, $visits);
    }
}
