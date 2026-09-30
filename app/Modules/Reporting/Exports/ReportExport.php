<?php

namespace App\Modules\Reporting\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The report (BuildReport) as an Excel workbook: a summary sheet with every figure of the page,
 * then one sheet each for customers, technicians and parts. Labels are the ones of the page
 * (lang/th/ui.php "reports"), so the two always read the same.
 */
class ReportExport implements Export, WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array<string, mixed>  $report  from BuildReport
     */
    public function __construct(private array $report) {}

    public function sheets(): array
    {
        $report = $this->report;
        $sheets = [new ReportSheet(__('ui.reports.sheets.summary'), [
            __('ui.reports.excel.section'), __('ui.reports.excel.metric'), __('ui.reports.excel.value'),
        ], $this->summary())];

        if ($report['customers'] !== []) {
            $sheets[] = new ReportSheet(__('ui.reports.sheets.customers'), [
                __('ui.reports.customer'), __('ui.reports.tickets_count'),
            ], array_map(fn (array $row) => [$row['name'], $row['tickets']], $report['customers']));
        }

        if ($report['technicians'] !== []) {
            $sheets[] = new ReportSheet(__('ui.reports.sheets.technicians'), [
                __('ui.reports.technician'), __('ui.reports.tickets_count'), __('ui.reports.closed_count'),
                __('ui.reports.resolve_breached'), __('ui.reports.survey_answers'), __('ui.reports.survey_average'),
            ], array_map(fn (array $row) => [
                $row['name'], $row['tickets'], $row['closed'], $row['resolve_breached'], $row['answers'], $row['average'],
            ], $report['technicians']));
        }

        if (($report['parts']['top'] ?? []) !== []) {
            $sheets[] = new ReportSheet(__('ui.reports.sheets.parts'), [
                __('ui.parts.code'), __('ui.parts.name'), __('ui.reports.quantity'), __('ui.parts.unit'), __('ui.reports.value'),
            ], array_map(fn (array $row) => [
                $row['code'], $row['name'], $row['quantity'], $row['unit'], $row['value'],
            ], $report['parts']['top']));
        }

        return $sheets;
    }

    /**
     * @return list<list<mixed>> section, metric, value
     */
    private function summary(): array
    {
        $report = $this->report;
        $rows = [[__('ui.reports.period'), __('ui.reports.from'), $report['period']['from']], ['', __('ui.reports.to'), $report['period']['to']]];
        $add = function (string $section, array $metrics) use (&$rows) {
            foreach ($metrics as $label => $value) {
                $rows[] = [$section, $label, $value];
                $section = '';
            }
        };
        $percent = fn (?int $value) => $value === null ? null : "{$value}%";

        if ($tickets = $report['tickets']) {
            $add(__('ui.reports.sections.tickets'), [
                __('ui.reports.opened') => $tickets['opened'],
                __('ui.reports.closed') => $tickets['closed'],
                __('ui.reports.cancelled') => $tickets['cancelled'],
                __('ui.reports.backlog') => $tickets['backlog'],
                __('ui.reports.avg_resolve_hours') => $tickets['avg_resolve_hours'],
                ...collect($tickets['by_status'])->mapWithKeys(fn (int $count, string $status) => [
                    __('ui.reports.status_prefix').' '.__("ui.tickets.statuses.{$status}") => $count,
                ])->all(),
                ...collect($tickets['by_priority'])->mapWithKeys(fn (int $count, string $priority) => [
                    __('ui.reports.priority_prefix').' '.__("ui.tickets.priorities.{$priority}") => $count,
                ])->all(),
            ]);

            foreach (['response', 'resolve'] as $clock) {
                $add(__("ui.reports.sections.sla_{$clock}"), [
                    __('ui.reports.sla_met') => $tickets['sla'][$clock]['met'],
                    __('ui.reports.sla_breached') => $tickets['sla'][$clock]['breached'],
                    __('ui.reports.sla_pending') => $tickets['sla'][$clock]['pending'],
                    __('ui.reports.sla_rate') => $percent($tickets['sla'][$clock]['rate']),
                ]);
            }
        }

        if ($pm = $report['pm']) {
            $add(__('ui.reports.sections.pm'), [
                __('ui.reports.pm_due') => $pm['due'],
                __('ui.reports.pm_completed') => $pm['completed'],
                __('ui.reports.pm_on_time') => $pm['on_time'],
                __('ui.reports.pm_in_progress') => $pm['in_progress'],
                __('ui.reports.pm_scheduled') => $pm['scheduled'],
                __('ui.reports.pm_overdue') => $pm['overdue'],
                __('ui.reports.pm_cancelled') => $pm['cancelled'],
                __('ui.reports.pm_compliance') => $percent($pm['compliance']),
                __('ui.reports.pm_items_ok') => $pm['items']['ok'],
                __('ui.reports.pm_items_issue') => $pm['items']['issue'],
            ]);
        }

        if ($assets = $report['assets']) {
            $add(__('ui.reports.sections.assets'), [
                __('ui.reports.assets_total') => $assets['total'],
                ...collect($assets['by_status'])->mapWithKeys(fn (int $count, string $status) => [__("ui.assets.statuses.{$status}") => $count])->all(),
                __('ui.reports.warranty_expiring', ['days' => $assets['expiring_days']]) => $assets['warranty_expiring'],
                __('ui.reports.warranty_expired') => $assets['warranty_expired'],
            ]);
        }

        if ($parts = $report['parts']) {
            $add(__('ui.reports.sections.parts'), [
                __('ui.reports.parts_received') => $parts['received'],
                __('ui.reports.parts_used') => $parts['used'],
                __('ui.reports.parts_used_on_tickets') => $parts['used_on_tickets'],
                __('ui.reports.parts_used_value') => $parts['used_value'],
                __('ui.reports.parts_low') => $parts['low'],
                __('ui.reports.parts_out') => $parts['out'],
            ]);
        }

        if ($surveys = $report['surveys']) {
            $add(__('ui.reports.sections.surveys'), [
                __('ui.reports.survey_sent') => $surveys['sent'],
                __('ui.reports.survey_answered') => $surveys['answered'],
                __('ui.reports.survey_rate') => $percent($surveys['response_rate']),
                __('ui.reports.survey_average') => $surveys['average'],
            ]);
        }

        return $rows;
    }
}
