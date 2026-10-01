<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Actions\SearchActivityLog;
use App\Modules\Platform\Models\Activity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who did what in the company, for the last SearchActivityLog::KEEP_DAYS days (activity-log.view).
 */
class ActivityLogController extends Controller
{
    public const PERMISSION = 'activity-log.view';

    public function __invoke(Request $request, SearchActivityLog $search): Response
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);
        $filters = SearchActivityLog::filtersFrom($request);

        return Inertia::render('Platform/ActivityLog', [
            'entries' => $search->handle($filters)->paginate(30)->withQueryString()->through(fn (Activity $activity) => [
                'id' => $activity->id,
                'at' => $activity->created_at?->toIso8601String(),
                'description' => $activity->description,
                'event' => $activity->event,
                'subject' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                'subject_id' => $activity->subject_id,
                'actor' => $activity->properties['actor']['name'] ?? null,
                'actor_email' => $activity->properties['actor']['email'] ?? null,
                'impersonating' => (bool) ($activity->properties['impersonating'] ?? false),
                // What changed, without who did it (shown in its own column).
                'details' => collect($activity->properties)->except(['actor', 'impersonating'])->all(),
            ]),
            'filters' => $filters,
            // The kinds of record in the log, for the filter.
            'subjects' => Activity::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')
                ->map(fn (string $type) => ['value' => $type, 'label' => class_basename($type)])->values(),
            'keepDays' => SearchActivityLog::KEEP_DAYS,
        ]);
    }
}
