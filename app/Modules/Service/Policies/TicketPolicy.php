<?php

namespace App\Modules\Service\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * ticket.* permissions. On top of the branch rule of TenantPolicy, the assignee may always see
 * and work on their ticket (a technician can be sent to another branch).
 *
 *   work (start, hold)    ticket.update, and be the assignee or hold ticket.assign
 *   resolve               ticket.close,  and be the assignee or hold ticket.assign
 *   approve (close/reject) ticket.approve
 *   assign, cancel        ticket.assign
 */
class TicketPolicy extends TenantPolicy
{
    protected string $module = 'ticket';

    public function view(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'view') && ($this->inScope($user, $ticket) || $this->isAssignee($user, $ticket));
    }

    public function update(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'update') && ($this->inScope($user, $ticket) || $this->isAssignee($user, $ticket));
    }

    public function assign(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'assign') && $this->inScope($user, $ticket);
    }

    public function work(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'update') && $this->ownsOrManages($user, $ticket);
    }

    public function resolve(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'close') && $this->ownsOrManages($user, $ticket);
    }

    public function approve(User $user, Model $ticket): bool
    {
        return $this->permits($user, 'approve') && $this->inScope($user, $ticket);
    }

    public function cancel(User $user, Model $ticket): bool
    {
        return $this->assign($user, $ticket);
    }

    public function comment(User $user, Model $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    private function ownsOrManages(User $user, Model $ticket): bool
    {
        return $this->isAssignee($user, $ticket) || $this->assign($user, $ticket);
    }

    private function isAssignee(User $user, Model $ticket): bool
    {
        return (int) $ticket->getAttribute('tenant_id') === (int) $user->tenant_id
            && $ticket->getAttribute('assignee_id') !== null
            && (int) $ticket->getAttribute('assignee_id') === (int) $user->id;
    }
}
