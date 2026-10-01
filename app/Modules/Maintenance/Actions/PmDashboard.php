<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;

/**
 * The PM figures of the home page, within what the user may see (SearchPmVisits), for the
 * Platform module's dashboard.
 */
class PmDashboard
{
    public function __construct(private SearchPmVisits $search) {}

    /**
     * @return array{overdue: int, this_month: int, mine: int}
     */
    public function handle(User $user): array
    {
        $count = fn (array $filters) => $this->search->handle($user, $filters)->count();

        return [
            'overdue' => $count(['status' => 'overdue']),
            'this_month' => $count(['status' => 'open', 'month' => now()->format('Y-m')]),
            'mine' => $count(['status' => 'open', 'assignee' => 'me']),
        ];
    }
}
