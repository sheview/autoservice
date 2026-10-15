<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface ReportRow {
    ulid: string;
    visit_no: string;
    title: string | null;
    customer: string | null;
    published_version: number;
    items_count: number;
    issue_count: number;
    live_shares?: number;
    due_on: string;
    published_at: string | null;
    can_view: boolean;
}

interface Filters {
    search: string;
    customer_id: number | null;
    month: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    reports: Paginated<ReportRow>;
    filters: Filters;
    customers: { id: number; name: string }[];
    staff: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_customer_reports.title'), href: route('maintenance.customer-reports.index') }];
const filters = useListFilters('maintenance.customer-reports.index', props.filters);
const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
};
const columns = [
    { key: 'visit_no', sortable: true },
    { key: 'title', sortable: false },
    { key: 'customer', sortable: false },
    { key: 'due_on', sortable: true },
    { key: 'published_at', sortable: true },
];

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_customer_reports.title')" />

        <div class="space-y-6 p-4">
            <Heading
                :title="t('pm_customer_reports.title')"
                :description="staff ? t('pm_customer_reports.description') : t('pm_customer_reports.description_customer')"
            />

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('pm_customer_reports.search_placeholder')" />
                <select v-if="staff" v-model="filters.customer_id" :class="selectClass">
                    <option :value="null">{{ t('pm_visits.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <Input v-model="filters.month as string" type="month" class="w-44" :aria-label="t('pm_customer_reports.month')" />
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="inline-flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(`pm_customer_reports.columns.${column.key}`) }}
                                    <component
                                        :is="filters.direction === 'asc' ? ArrowUp : ArrowDown"
                                        v-if="filters.sort === column.key"
                                        class="h-3 w-3"
                                    />
                                </button>
                                <span v-else>{{ t(`pm_customer_reports.columns.${column.key}`) }}</span>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_customer_reports.columns.result') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('pm_customer_reports.columns.report') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="report in reports.data" :key="report.ulid" class="border-t">
                            <td class="px-4 py-2 font-mono text-xs">
                                <Link
                                    v-if="report.can_view"
                                    :href="route('maintenance.visits.show', report.ulid)"
                                    class="text-primary underline-offset-4 hover:underline"
                                    >{{ report.visit_no }}</Link
                                >
                                <span v-else>{{ report.visit_no }}</span>
                            </td>
                            <td class="px-4 py-2">{{ report.title }}</td>
                            <td class="px-4 py-2">{{ report.customer ?? t('common.none') }}</td>
                            <td class="px-4 py-2">{{ report.due_on }}</td>
                            <td class="px-4 py-2">{{ report.published_at ? dateTime(report.published_at) : '' }}</td>
                            <td class="px-4 py-2">
                                {{ t('pm_customer_reports.result', { total: report.items_count, issues: report.issue_count }) }}
                                <span v-if="staff && report.live_shares" class="ml-1 text-xs text-muted-foreground">{{
                                    t('pm_customer_reports.live_shares', { count: report.live_shares })
                                }}</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <a
                                    :href="route('maintenance.visits.pdf', report.ulid)"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-primary underline-offset-4 hover:underline"
                                    >{{ t('pm_customer_reports.open', { version: report.published_version }) }}</a
                                >
                            </td>
                        </tr>
                        <tr v-if="reports.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="reports" />
        </div>
    </AppLayout>
</template>
