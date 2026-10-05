<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Asset\Actions\AssetReport;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Actions\UserNames;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Inventory\Actions\PartUsageReport;
use App\Modules\Maintenance\Actions\PmReport;
use App\Modules\Platform\Support\Modules;
use App\Modules\Reporting\Support\ReportPeriod;
use App\Modules\Service\Actions\TicketReport;
use App\Modules\Survey\Actions\SurveyReport;

/**
 * The management report of the current tenant for a period: one section per business module,
 * each built by that module's own report action. A section is null when its module is switched
 * off. Ids are turned into names here, so the page and the Excel export get the same thing.
 *
 * A viewer whose permission has scope customer gets only that customer's figures (tickets, PM,
 * surveys). What cannot be narrowed to one customer (assets) or is internal (technicians, the
 * customer ranking, stock, parts and costs) is left out for them.
 */
class BuildReport
{
    public const TOP_CUSTOMERS = 10;

    public function __construct(
        private Modules $modules,
        private TicketReport $ticketReport,
        private PmReport $pmReport,
        private AssetReport $assetReport,
        private PartUsageReport $partUsageReport,
        private SurveyReport $surveyReport,
        private ListCustomers $listCustomers,
        private UserNames $userNames,
    ) {}

    /**
     * @return array{period: array{from: string, to: string}, tickets: array<string, mixed>|null,
     *     customers: list<array{name: string, tickets: int}>,
     *     technicians: list<array{name: string, tickets: int, closed: int, resolve_breached: int, answers: int, average: float|null}>,
     *     pm: array<string, mixed>|null, assets: array<string, mixed>|null, parts: array<string, mixed>|null,
     *     surveys: array<string, mixed>|null}
     */
    public function handle(ReportPeriod $period, ?User $viewer = null, string $permission = 'reports.view'): array
    {
        $on = fn (string $module) => $this->modules->enabled($module);

        if ($viewer !== null && DataScope::of($viewer, $permission) === PermissionCatalog::SCOPE_CUSTOMER) {
            return $this->forCustomer($period, (int) $viewer->customer_id);
        }

        $tickets = $on('service') ? $this->ticketReport->handle($period->from, $period->to) : null;
        // Surveys are about tickets: without the Service module there is nothing to show them against.
        $surveys = $on('survey') && $on('service') ? $this->surveyReport->handle($period->from, $period->to) : null;

        return [
            'period' => $period->toArray(),
            'tickets' => $tickets ? collect($tickets)->except(['by_customer', 'by_assignee', 'by_title'])->all() : null,
            ...$this->topics($tickets['by_title'] ?? []),
            'customers' => $this->customers($tickets['by_customer'] ?? []),
            'technicians' => $this->technicians($tickets['by_assignee'] ?? [], $surveys['by_assignee'] ?? []),
            'pm' => $on('maintenance') ? $this->pmReport->handle($period->from, $period->to) : null,
            'assets' => $on('asset') ? $this->assetReport->handle() : null,
            'parts' => $on('inventory') ? $this->partUsageReport->handle($period->from, $period->to) : null,
            'surveys' => $surveys ? collect($surveys)->except('by_assignee')->all() : null,
        ];
    }

    /**
     * The report of one customer: only what can be counted for that customer alone.
     *
     * @return array<string, mixed> the same keys as handle()
     */
    private function forCustomer(ReportPeriod $period, int $customerId): array
    {
        $on = fn (string $module) => $this->modules->enabled($module);
        $tickets = $on('service') ? $this->ticketReport->handle($period->from, $period->to, $customerId) : null;
        $surveys = $on('survey') && $on('service') ? $this->surveyReport->handle($period->from, $period->to, $customerId) : null;

        return [
            'period' => $period->toArray(),
            'tickets' => $tickets ? collect($tickets)->except(['by_customer', 'by_assignee', 'by_title'])->all() : null,
            ...$this->topics($tickets['by_title'] ?? []),
            'customers' => [],
            'technicians' => [],
            'pm' => $on('maintenance') ? $this->pmReport->handle($period->from, $period->to, $customerId) : null,
            // AssetReport counts the whole company: not for one customer.
            'assets' => null,
            'parts' => null,
            'surveys' => $surveys ? collect($surveys)->except('by_assignee')->all() : null,
        ];
    }

    /** The topics that come up most: this many. */
    public const TOP_TOPICS = 15;

    /**
     * The topics that come up most, each with the kind of work it belongs to (the groups of the
     * ticket title list; a title typed by hand is "other"), and every ticket counted by kind of work.
     *
     * @param  list<array{title: string, tickets: int, closed: int}>  $titles  most first
     * @return array{topics: list<array{title: string, group: string, tickets: int, closed: int}>, topic_groups: list<array{name: string, tickets: int}>}
     */
    private function topics(array $titles): array
    {
        $groups = [];
        foreach ((array) __('ui.tickets.title_presets') as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $groups[mb_strtolower($item)] = $group['label'];
            }
        }

        $rows = collect($titles)->map(fn (array $row) => $row + ['group' => $groups[mb_strtolower($row['title'])] ?? __('ui.reports.topic_other')]);

        return [
            'topics' => $rows->take(self::TOP_TOPICS)->values()->all(),
            'topic_groups' => $rows->groupBy('group')->map(fn ($same, string $name) => ['name' => $name, 'tickets' => $same->sum('tickets')])
                ->sortByDesc('tickets')->values()->all(),
        ];
    }

    /**
     * @param  array<int, int>  $counts  customer id => tickets, most first
     * @return list<array{name: string, tickets: int}>
     */
    private function customers(array $counts): array
    {
        if ($counts === [] || ! $this->modules->enabled('contract')) {
            return [];
        }

        $names = collect($this->listCustomers->handle(withTrashed: true))->pluck('name', 'id');

        return collect($counts)->take(self::TOP_CUSTOMERS)
            ->map(fn (int $tickets, int $customerId) => ['name' => $names[$customerId] ?? '-', 'tickets' => $tickets])
            ->values()->all();
    }

    /**
     * One row per technician who had tickets or survey answers in the period.
     *
     * @param  array<int, array{tickets: int, closed: int, resolve_breached: int}>  $work
     * @param  array<int, array{answers: int, average: float}>  $scores
     * @return list<array{name: string, tickets: int, closed: int, resolve_breached: int, answers: int, average: float|null}>
     */
    private function technicians(array $work, array $scores): array
    {
        $ids = array_values(array_unique([...array_keys($work), ...array_keys($scores)]));
        $names = $this->userNames->handle($ids);

        return collect($ids)->map(fn (int $id) => [
            'name' => $names[$id] ?? '-',
            'tickets' => $work[$id]['tickets'] ?? 0,
            'closed' => $work[$id]['closed'] ?? 0,
            'resolve_breached' => $work[$id]['resolve_breached'] ?? 0,
            'answers' => $scores[$id]['answers'] ?? 0,
            'average' => $scores[$id]['average'] ?? null,
        ])->sortByDesc('tickets')->values()->all();
    }
}
