<?php

namespace App\Modules\Survey\Support;

use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * The public link of a survey: /s/{tenant ulid}/{token}. The tenant is in the link because
 * whoever opens it is not signed in, so nothing else says which tenant to look in.
 */
class SurveyLink
{
    public function __construct(private TenantContext $context) {}

    public function for(TicketSurvey $survey): string
    {
        return PublicUrl::route('survey.public.show', ['tenant' => $this->context->tenant()->ulid, 'token' => $survey->token]);
    }
}
