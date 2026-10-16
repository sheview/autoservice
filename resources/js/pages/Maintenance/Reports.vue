<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import StatTile from '@/components/StatTile.vue';
import PmRoundSharePanel, { type PmRoundShare } from '@/components/pm/PmRoundSharePanel.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, CalendarClock, CalendarDays, CircleCheck, FileDown, Gauge, Printer, Timer, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

interface VisitRow {
    ulid: string;
    visit_no: string;
    status: string;
    title: string | null;
    customer: string | null;
    due_on: string;
    overdue: boolean;
    items_count: number;
    issue_count: number;
    current_version: number;
    completed_at: string | null;
}

interface Filters {
    from: string;
    to: string;
    customer_id: number | null;
    status: string | null;
    search: string;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    filters: Filters;
    summary: {
        due: number;
        done: number;
        on_time: number;
        overdue: number;
        cancelled: number;
        compliance: number | null;
        results: Record<string, number>;
    };
    byCustomer: { customer: string; due: number; done: number; overdue: number; issues: number }[];
    visits: Paginated<VisitRow>;
    customers: { id: number; name: string }[];
    statuses: string[];
    failing: { asset_id: number; asset_code: string | null; ulid: string | null; name: string | null; failed: number; last_on: string | null }[];
    technicians: {
        assignee_id: number;
        name: string | null;
        assigned: number;
        done: number;
        on_time: number;
        on_time_rate: number | null;
        checked: number;
        failed: number;
        onsite_minutes: number | null;
    }[];
    roundShares: PmRoundShare[];
    canShare: boolean;
    shareDays: number;
    maxShareDays: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_reports.title'), href: route('maintenance.reports.index') }];
const filters = useListFilters('maintenance.reports.index', props.filters);
const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const tiles = computed(() => [
    { key: 'due', value: props.summary.due, icon: CalendarDays, tone: 'violet' as const },
    { key: 'done', value: props.summary.done, icon: CircleCheck, tone: 'emerald' as const },
    { key: 'on_time', value: props.summary.on_time, icon: Timer, tone: 'teal' as const },
    { key: 'overdue', value: props.summary.overdue, icon: CalendarClock, tone: 'rose' as const },
    { key: 'compliance', value: props.summary.compliance === null ? '–' : `${props.summary.compliance}%`, icon: Gauge, tone: 'indigo' as const },
    { key: 'issues', value: props.summary.results.failed ?? 0, icon: TriangleAlert, tone: 'amber' as const },
]);

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_reports.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('pm_reports.title')" :description="t('pm_reports.description')" />

            <div class="flex flex-wrap items-end gap-3">
                <label class="grid gap-1 text-xs text-muted-foreground">
                    {{ t('pm_reports.from') }}
                    <Input v-model="filters.from" type="date" class="w-40" />
                </label>
                <label class="grid gap-1 text-xs text-muted-foreground">
                    {{ t('pm_reports.to') }}
                    <Input v-model="filters.to" type="date" class="w-40" />
                </label>
                <select v-model="filters.customer_id" :class="selectClass">
                    <option :value="null">{{ t('pm_visits.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <!-- One PDF of the approved work orders of the customer within the period -->
                <a
                    v-if="filters.customer_id"
                    :href="route('maintenance.reports.round', { customer_id: filters.customer_id, from: filters.from, to: filters.to })"
                    class="shadow-xs inline-flex h-9 items-center gap-2 rounded-md border px-3 text-sm hover:bg-muted"
                >
                    <FileDown class="h-4 w-4" />{{ t('pm_reports.round_report') }}
                </a>
                <a
                    v-if="filters.customer_id"
                    :href="route('maintenance.reports.round', { customer_id: filters.customer_id, from: filters.from, to: filters.to, print: 1 })"
                    target="_blank"
                    rel="noopener"
                    class="shadow-xs inline-flex h-9 items-center gap-2 rounded-md border px-3 text-sm hover:bg-muted"
                >
                    <Printer class="h-4 w-4" />{{ t('pm_reports.round_print') }}
                </a>
                <span v-else class="self-center text-xs text-muted-foreground">{{ t('pm_reports.round_report_hint') }}</span>
            </div>

            <!-- A link for the customer to the same round, without an account -->
            <PmRoundSharePanel
                v-if="canShare && filters.customer_id"
                :key="filters.customer_id"
                :customer-id="filters.customer_id"
                :from="filters.from"
                :to="filters.to"
                :shares="roundShares"
                :share-days="shareDays"
                :max-share-days="maxShareDays"
            />

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                <StatTile
                    v-for="tile in tiles"
                    :key="tile.key"
                    :label="t(`pm_reports.tiles.${tile.key}`)"
                    :value="tile.value"
                    :icon="tile.icon"
                    :tone="tile.tone"
                />
            </div>

            <!-- By customer -->
            <section v-if="byCustomer.length && !filters.customer_id" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('pm_reports.by_customer') }}</h3>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/40 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('pm_visits.customer') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.tiles.due') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.tiles.done') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.tiles.overdue') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.tiles.issues') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in byCustomer" :key="row.customer" class="border-t">
                                <td class="px-4 py-2">{{ row.customer }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ row.due }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ row.done }}</td>
                                <td
                                    class="px-4 py-2 text-right tabular-nums"
                                    :class="{ 'font-semibold text-red-700 dark:text-red-400': row.overdue }"
                                >
                                    {{ row.overdue }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ row.issues }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-2">
                <!-- The equipment found failed most often -->
                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('pm_reports.failing') }}</h3>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/40 text-left">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ t('pm_reports.asset') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.failed_times') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.last_failed') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in failing" :key="row.asset_id" class="border-t">
                                    <td class="px-4 py-2">
                                        <Link
                                            v-if="row.ulid"
                                            :href="route('asset.assets.show', row.ulid)"
                                            class="text-primary underline-offset-4 hover:underline"
                                            ><span class="font-mono text-xs">{{ row.asset_code }}</span> {{ row.name }}</Link
                                        >
                                        <span v-else>-</span>
                                    </td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums text-red-700 dark:text-red-400">{{ row.failed }}</td>
                                    <td class="px-4 py-2 text-right">{{ row.last_on ?? '-' }}</td>
                                </tr>
                                <tr v-if="failing.length === 0">
                                    <td colspan="3" class="px-4 py-6 text-center text-muted-foreground">{{ t('pm_reports.no_failing') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- The work of each technician -->
                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('pm_reports.technicians') }}</h3>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/40 text-left">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ t('pm_reports.technician') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.assigned') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.tiles.done') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.on_time_rate') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.checked') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.failed_found') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('pm_reports.onsite_minutes') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in technicians" :key="row.assignee_id" class="border-t">
                                    <td class="px-4 py-2">{{ row.name ?? '-' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ row.assigned }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ row.done }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ row.on_time_rate === null ? '–' : `${row.on_time_rate}%` }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ row.checked }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ row.failed }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ row.onsite_minutes === null ? '–' : t('pm_reports.minutes', { minutes: row.onsite_minutes }) }}
                                    </td>
                                </tr>
                                <tr v-if="technicians.length === 0">
                                    <td colspan="7" class="px-4 py-6 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- The work orders of the period -->
            <section class="space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h3 class="text-sm font-semibold">{{ t('pm_reports.work_orders') }}</h3>
                    <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('pm_visits.search_placeholder')" />
                    <select v-model="filters.status" :class="selectClass">
                        <option :value="null">{{ t('pm_visits.all_statuses') }}</option>
                        <option v-for="status in statuses" :key="status" :value="status">{{ t(`pm_visits.statuses.${status}`) }}</option>
                    </select>
                </div>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/40 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">
                                    <button type="button" class="inline-flex items-center gap-1" @click="sortBy('visit_no')">
                                        {{ t('pm_visits.visit_no') }}
                                        <component
                                            :is="filters.direction === 'asc' ? ArrowUp : ArrowDown"
                                            v-if="filters.sort === 'visit_no'"
                                            class="h-3 w-3"
                                        />
                                    </button>
                                </th>
                                <th class="px-4 py-2 font-medium">{{ t('pm_customer_reports.columns.title') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('pm_visits.customer') }}</th>
                                <th class="px-4 py-2 font-medium">
                                    <button type="button" class="inline-flex items-center gap-1" @click="sortBy('due_on')">
                                        {{ t('pm_visits.due_on') }}
                                        <component
                                            :is="filters.direction === 'asc' ? ArrowUp : ArrowDown"
                                            v-if="filters.sort === 'due_on'"
                                            class="h-3 w-3"
                                        />
                                    </button>
                                </th>
                                <th class="px-4 py-2 font-medium">{{ t('pm_visits.status') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('pm_customer_reports.columns.result') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('pm_customer_reports.columns.report') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="visit in visits.data" :key="visit.ulid" class="border-t">
                                <td class="px-4 py-2 font-mono text-xs">
                                    <Link
                                        :href="route('maintenance.visits.show', visit.ulid)"
                                        class="text-primary underline-offset-4 hover:underline"
                                        >{{ visit.visit_no }}</Link
                                    >
                                </td>
                                <td class="px-4 py-2">{{ visit.title }}</td>
                                <td class="px-4 py-2">{{ visit.customer ?? t('common.none') }}</td>
                                <td class="px-4 py-2">{{ visit.due_on }}</td>
                                <td class="px-4 py-2"><PmVisitStatusBadge :status="visit.status" :overdue="visit.overdue" /></td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ t('pm_customer_reports.result', { total: visit.items_count, issues: visit.issue_count }) }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <a
                                        :href="route('maintenance.visits.pdf', visit.ulid)"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-primary underline-offset-4 hover:underline"
                                        >{{
                                            visit.current_version
                                                ? t('pm_visits.version', { version: visit.current_version })
                                                : t('pm_reports.draft_pdf')
                                        }}</a
                                    >
                                </td>
                            </tr>
                            <tr v-if="visits.data.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="visits" />
            </section>
        </div>
    </AppLayout>
</template>
