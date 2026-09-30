<?php

namespace App\Modules\Survey\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Support\Modules;
use App\Modules\Survey\Actions\AnswerTicketSurvey;
use App\Modules\Survey\Models\TicketSurvey;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The survey page behind the public link (/s/{tenant ulid}/{token}): no sign-in. The tenant comes
 * from the link and the work runs inside it, so the survey is found through the tenant scope and
 * RLS like anything else; a wrong tenant, a wrong token or a module switched off is a 404.
 * The page shows nothing but the ticket number and title, and takes one answer.
 */
class PublicSurveyController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private Modules $modules,
    ) {}

    public function show(string $tenant, string $token): Response
    {
        [$company, $survey] = $this->find($tenant, $token);

        return Inertia::render('Survey/Public', [
            'company' => $company->name,
            'survey' => [
                ...$survey->only(['ticket_no', 'ticket_title', 'score']),
                'answered' => $survey->isAnswered(),
            ],
            'action' => route('survey.public.store', ['tenant' => $tenant, 'token' => $token]),
            'maxScore' => TicketSurvey::MAX_SCORE,
        ]);
    }

    public function store(Request $request, string $tenant, string $token, AnswerTicketSurvey $answerSurvey): RedirectResponse
    {
        [$company, $survey] = $this->find($tenant, $token);

        $answer = $request->validate([
            'score' => ['required', 'integer', 'between:'.TicketSurvey::MIN_SCORE.','.TicketSurvey::MAX_SCORE],
            'comment' => ['nullable', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:100'],
        ], attributes: __('survey.fields'));

        $this->context->run($company, fn () => $answerSurvey->handle($survey, $answer));

        return back()->with('success', __('survey.thanks'));
    }

    /**
     * @return array{0: Tenant, 1: TicketSurvey}
     */
    private function find(string $tenant, string $token): array
    {
        $company = Tenant::query()->where('ulid', $tenant)->where('is_platform', false)->first();
        abort_unless($company?->isActive() && $this->modules->enabled('survey', $company), 404);

        $survey = $this->context->run($company, fn () => TicketSurvey::query()->where('token', $token)->first());
        abort_if($survey === null, 404);

        return [$company, $survey];
    }
}
