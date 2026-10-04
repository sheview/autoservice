<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Service\Actions\MyWork;
use App\Modules\Service\Actions\SavePersonalEvent;
use App\Modules\Service\Models\PersonalEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My work": the user's calendar (assigned tickets, loans to give back, own appointments) and
 * to-do list. Staff only. Own appointments are the user's alone: any other's is not found.
 */
class MyWorkController extends Controller
{
    public function index(Request $request, MyWork $myWork): Response
    {
        $user = $this->staff($request);
        $tz = config('app.timezone');

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month'))
            ? CarbonImmutable::createFromFormat('Y-m-d', $request->input('month').'-01', $tz)->startOfDay()
            : CarbonImmutable::now($tz)->startOfMonth();
        // Whole weeks, Sunday first, as Thai wall calendars run.
        $from = $month->startOfWeek(CarbonImmutable::SUNDAY);
        $to = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY)->startOfDay();

        return Inertia::render('Service/MyWork/Index', [
            'month' => $month->format('Y-m'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => CarbonImmutable::now($tz)->toDateString(),
            ...$myWork->handle($user, $from, $to),
        ]);
    }

    public function store(Request $request, SavePersonalEvent $save): RedirectResponse
    {
        $user = $this->staff($request);
        $save->handle($user, null, $this->eventData($request));

        return back()->with('success', __('service.my_work.saved'));
    }

    public function update(Request $request, PersonalEvent $event, SavePersonalEvent $save): RedirectResponse
    {
        $user = $this->staff($request);
        abort_unless($event->user_id === (int) $user->id, 404);
        $save->handle($user, $event, $this->eventData($request));

        return back()->with('success', __('service.my_work.saved'));
    }

    public function destroy(Request $request, PersonalEvent $event): RedirectResponse
    {
        $user = $this->staff($request);
        abort_unless($event->user_id === (int) $user->id, 404);
        $event->delete();

        return back()->with('success', __('service.my_work.deleted'));
    }

    /**
     * @return array{title: string, starts_at: string, ends_at: string|null, all_day: bool, notes: string|null}
     */
    private function eventData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'all_day' => ['boolean'],
            'start_time' => ['nullable', 'required_unless:all_day,true', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: __('service.my_work.fields'));

        $allDay = (bool) ($data['all_day'] ?? false);
        $tz = config('app.timezone');
        $at = fn (?string $time) => $time ? CarbonImmutable::createFromFormat('Y-m-d H:i', "{$data['date']} {$time}", $tz)->toDateTimeString() : null;

        return [
            'title' => $data['title'],
            'starts_at' => $allDay ? CarbonImmutable::createFromFormat('Y-m-d', $data['date'], $tz)->startOfDay()->toDateTimeString() : $at($data['start_time']),
            'ends_at' => $allDay ? null : $at($data['end_time'] ?? null),
            'all_day' => $allDay,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function staff(Request $request)
    {
        $user = $request->user();
        abort_if($user->customer_id !== null, 403);

        return $user;
    }
}
