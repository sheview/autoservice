<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UsersWithPermission;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Notifications\UpcomingPmNotification;
use Illuminate\Support\Facades\Notification;

/**
 * For the current tenant: e-mails about rounds that have not started and whose date (the
 * appointment, or the due date when there is none) is within REMIND_DAYS, or already passed.
 * Each technician gets their own rounds; rounds without a technician go to the users with
 * pm.update, so someone assigns them. Each round is e-mailed once; a new date or technician
 * re-arms it (UpdatePmVisit, SavePmPlan).
 */
class NotifyUpcomingPm
{
    public const REMIND_DAYS = 7;

    public const MANAGER_PERMISSION = 'pm.update';

    public function __construct(
        private UsersWithPermission $usersWithPermission,
        private ListCustomers $listCustomers,
    ) {}

    /**
     * @return int number of rounds notified
     */
    public function handle(): int
    {
        $until = today()->addDays(self::REMIND_DAYS)->toDateString();

        $visits = PmVisit::query()
            ->with('plan:id,title')
            ->where('status', PmVisit::STATUS_SCHEDULED)
            ->whereNull('reminded_at')
            ->whereRaw('coalesce(scheduled_on, due_on) <= ?::date', [$until])
            ->orderByRaw('coalesce(scheduled_on, due_on)')
            ->get();

        if ($visits->isEmpty()) {
            return 0;
        }

        $customers = collect($this->listCustomers->handle(withTrashed: true))->pluck('name', 'id')->all();
        $staff = $this->usersWithPermission->handle('pm.perform')
            ->merge($this->usersWithPermission->handle(self::MANAGER_PERMISSION))
            ->unique('id')
            ->keyBy('id');

        foreach ($visits->whereNotNull('assignee_id')->groupBy('assignee_id') as $assigneeId => $rounds) {
            if ($user = $staff->get($assigneeId)) {
                Notification::sendNow($user, new UpcomingPmNotification($rounds, $customers, forManagers: false));
            }
        }

        $unassigned = $visits->whereNull('assignee_id')->values();
        $managers = $staff->filter(fn ($user) => $user->can(self::MANAGER_PERMISSION))->values();
        if ($unassigned->isNotEmpty() && $managers->isNotEmpty()) {
            Notification::sendNow($managers, new UpcomingPmNotification($unassigned, $customers, forManagers: true));
        }

        PmVisit::whereKey($visits->modelKeys())->update(['reminded_at' => now()]);

        return $visits->count();
    }
}
