<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface PlanRow {
    id: number;
    title: string;
    interval_months: number;
    visits_count: number;
    completed_count: number;
    overdue_count: number;
    customer: string | null;
    contract_no: string | null;
    assignee: string | null;
    starts_on: string;
    ends_on: string;
}

interface Filters {
    search: string;
    customer_id: number | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    plans: Paginated<PlanRow>;
    filters: Filters;
    customers: { id: number; code: string; name: string }[];
    can: { create: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_plans.title'), href: route('maintenance.plans.index') }];

const filters = useListFilters('maintenance.plans.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const columns = [
    { key: 'title', label: 'pm_plans.title_field', sortable: true },
    { key: 'customer', label: 'pm_plans.customer', sortable: false },
    { key: 'ends_on', label: 'pm_plans.period', sortable: true },
    { key: 'interval_months', label: 'pm_plans.interval', sortable: true },
    { key: 'assignee', label: 'pm_plans.assignee', sortable: false },
    { key: 'progress', label: 'pm_plans.progress', sortable: false },
];

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_plans.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('pm_plans.title')" :description="t('pm_plans.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('maintenance.plans.create')">{{ t('pm_plans.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('pm_plans.search_placeholder')" />
                <select v-model="filters.customer_id" :class="selectClass" :aria-label="t('pm_plans.customer')">
                    <option :value="null">{{ t('pm_plans.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="whitespace-nowrap px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(column.label) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(column.label) }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="plan in plans.data" :key="plan.id" class="border-t">
                            <td class="px-4 py-2">
                                <Link
                                    :href="route('maintenance.plans.show', plan.id)"
                                    class="font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    {{ plan.title }}
                                </Link>
                                <div class="font-mono text-xs text-muted-foreground">{{ plan.contract_no }}</div>
                            </td>
                            <td class="px-4 py-2">{{ plan.customer ?? t('common.none') }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ plan.starts_on }} – {{ plan.ends_on }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ t('pm_plans.interval_months', { count: plan.interval_months }) }}</td>
                            <td class="px-4 py-2">{{ plan.assignee ?? t('pm_plans.no_assignee') }}</td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <div>{{ t('pm_plans.progress_value', { done: plan.completed_count, total: plan.visits_count }) }}</div>
                                <div v-if="plan.overdue_count" class="text-xs text-red-600 dark:text-red-400">
                                    {{ t('pm_plans.overdue_count', { count: plan.overdue_count }) }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="plans.data.length === 0">
                            <td :colspan="columns.length" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="plans" />
        </div>
    </AppLayout>
</template>
