<?php

namespace App\Modules\Survey\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * surveys.view: the survey pages, within the scope (no branches; customer = the survey's
 * customer; own = surveys of the jobs the user did). Answering needs surveys.respond and is
 * decided on the ticket (Service module), or needs nothing but the public link.
 */
class TicketSurveyPolicy extends TenantPolicy
{
    protected string $resource = 'surveys';

    protected ?string $branchColumn = null;

    /** Scope "own": the survey of a job assigned to the user. */
    protected function owns(User $user, Model $survey): bool
    {
        return $survey->getAttribute('assignee_id') !== null && (int) $survey->getAttribute('assignee_id') === (int) $user->id;
    }
}
