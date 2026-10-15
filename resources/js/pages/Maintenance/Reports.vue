<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';
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
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_reports.title'), href: route('maintenance.reports.index') }];
const filters = useListFilters('maintenance.reports.index', props.filters);
const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const tiles = computed(() => [
    { key: 'due', value: props.summary.due },
    { key: 'done', value: props.summary.done },
    { key: 'on_time', value: props.summary.on_time },
    { key: 'overdue', value: props.summary.overdue },
    { key: 'compliance', value: props.summary.compliance === null ? '–' : `${props.summary.compliance}%` },
    { key: 'issues', value: props.summary.results.failed ?? 0 },
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
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <div v-for="tile in tiles" :key="tile.key" class="rounded-lg border p-3">
                    <div class="text-sm text-muted-foreground">{{ t(`pm_reports.tiles.${tile.key}`) }}</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ tile.value }}</div>
                </div>
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
