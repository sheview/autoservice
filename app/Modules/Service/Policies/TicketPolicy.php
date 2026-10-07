<?php

namespace App\Modules\Service\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use App\Modules\Identity\Support\DataScope;
use Illuminate\Database\Eloquent\Model;

/**
 * tickets.* permissions, each within its scope (DataScope): all / branch (branch_id) /
 * customer (customer_id) / own (reported by the user or assigned to them). On top of that the
 * assignee may always see and work on their ticket (a technician can be sent to another branch).
 *
 *   work (start, hold)     tickets.update, and be the assignee or hold tickets.assign
 *   resolve                same as work (reporting the fix is part of the job)
 *   approve (close/reject) tickets.approve (the customer confirms the repair) or tickets.close (the office closes it)
 *   assign, cancel         tickets.assign
 *   issueParts             parts.issue (scope own: only to the user's own tickets) and update
 */
class TicketPolicy extends TenantPolicy
{
    protected string $resource = 'tickets';

    protected ?string $projectColumn = 'contract_id';

    public function view(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'view') && ($this->inScope($user, $ticket, 'view') || $this->isAssignee($user, $ticket));
    }

    public function update(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'update') && ($this->inScope($user, $ticket, 'update') || $this->isAssignee($user, $ticket));
    }

    public function assign(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'assign') && $this->inScope($user, $ticket, 'assign');
    }

    public function work(User $user, Model $ticket): bool
    {
        return $this->update($user, $ticket) && $this->ownsOrManages($user, $ticket);
    }

    /** Reporting the fix is part of the job itself (permissions.json gives technicians update, not close). */
    public function resolve(User $user, Model $ticket): bool
    {
        return $this->work($user, $ticket);
    }

    /** The customer confirms (tickets.approve), or the office closes it for them (tickets.close). */
    public function approve(User $user, Model $ticket): bool
    {
        return ($this->permits($user, 'approve') && $this->inScope($user, $ticket, 'approve'))
            || ($this->permits($user, 'close') && $this->inScope($user, $ticket, 'close'));
    }

    public function cancel(User $user, Model $ticket): bool
    {
        return $this->assign($user, $ticket);
    }

    public function comment(User $user, Model $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /**
     * Taking spare parts out of stock for the job: parts.issue, and for scope "own" only on the
     * user's own tickets; and the user may work on the ticket.
     */
    public function issueParts(User $user, Model $ticket): bool
    {
        return $user->checkPermissionTo('parts.issue')
            && DataScope::covers($ticket, $user, 'parts.issue', branch: null, customer: null, own: fn (Model $t) => $this->owns($user, $t))
            && $this->update($user, $ticket);
    }

    /** Scope "own": the user reported the ticket or is its assignee. */
    protected function owns(User $user, Model $ticket): bool
    {
        return (int) $ticket->getAttribute('reported_by') === (int) $user->id
            || (int) $ticket->getAttribute('assignee_id') === (int) $user->id;
    }

    private function ownsOrManages(User $user, Model $ticket): bool
    {
        return $this->isAssignee($user, $ticket) || $this->assign($user, $ticket)
            || ($this->actsFromPlatform($user) && $this->inScope($user, $ticket));
    }

    private function isAssignee(User $user, Model $ticket): bool
    {
        return (int) $ticket->getAttribute('tenant_id') === $this->tenantIdOf($user)
            && $ticket->getAttribute('assignee_id') !== null
            && (int) $ticket->getAttribute('assignee_id') === (int) $user->id;
    }
}
