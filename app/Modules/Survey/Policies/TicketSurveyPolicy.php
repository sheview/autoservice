<?php

namespace App\Modules\Survey\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

/**
 * survey.view: the staff pages with every answer. Answering needs survey.answer and is decided
 * on the ticket (Service module), or needs nothing but the public link.
 */
class TicketSurveyPolicy extends TenantPolicy
{
    protected string $module = 'survey';
}
