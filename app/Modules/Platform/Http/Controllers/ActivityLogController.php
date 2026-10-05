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

    /** What the models log by themselves (spatie/laravel-activitylog). */
    private const MODEL_EVENTS = ['created', 'updated', 'deleted', 'restored'];

    /** The kind of record in Thai (ui.activity_log.subjects), or its class name when there is none. */
    private static function subjectLabel(?string $type): string
    {
        return self::label('subjects', class_basename((string) $type));
    }

    /** ui.activity_log.{$group}.{$name} in Thai, or the name itself when there is no text for it. */
    private static function label(string $group, string $name): string
    {
        $key = "ui.activity_log.{$group}.{$name}";

        return __($key) === $key ? $name : __($key);
    }

    public function __invoke(Request $request, SearchActivityLog $search): Response
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);
        $filters = SearchActivityLog::filtersFrom($request);

        return Inertia::render('Platform/ActivityLog', [
            'entries' => $search->handle($filters)->paginate(30)->withQueryString()->through(fn (Activity $activity) => [
                'id' => $activity->id,
                'at' => $activity->created_at?->toIso8601String(),
                // Records logged by the models say only created / updated / deleted: in words here.
                'description' => in_array($activity->description, self::MODEL_EVENTS, true)
                    ? __("ui.activity_log.descriptions.{$activity->description}", ['subject' => self::subjectLabel($activity->subject_type)])
                    : $activity->description,
                'event' => $activity->event,
                'subject' => $activity->subject_type ? self::subjectLabel($activity->subject_type) : null,
                'subject_id' => $activity->subject_id,
                'actor' => $activity->properties['actor']['name'] ?? null,
                'actor_email' => $activity->properties['actor']['email'] ?? null,
                'impersonating' => (bool) ($activity->properties['impersonating'] ?? false),
                // What changed, without who did it (shown in its own column).
                'details' => collect($activity->properties)->except(['actor', 'impersonating'])
                    ->mapWithKeys(fn ($value, string $key) => [self::label('detail_keys', $key) => $value])
                    ->all(),
            ]),
            'filters' => $filters,
            // The kinds of record in the log, for the filter.
            'subjects' => Activity::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')
                ->map(fn (string $type) => ['value' => $type, 'label' => self::subjectLabel($type)])->values(),
            'keepDays' => SearchActivityLog::KEEP_DAYS,
        ]);
    }
}
