<script setup lang="ts">
import ContractPhaseBadge from '@/components/ContractPhaseBadge.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import type { ProjectSummary } from '@/types/summary';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface Filters {
    search: string;
    customer_id: number | null;
    phase: string | null;
    items: 'with' | 'all';
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    projects: Paginated<ProjectSummary>;
    filters: Filters;
    customers: { id: number; code: string; name: string }[];
    phases: string[];
    // False for customer accounts: no purchases or amounts.
    showsPurchases: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('summary.projects_title'), href: route('reporting.projects.index') }];
// service_window is a filter of the contract list that this page does not offer.
const filters = useListFilters('reporting.projects.index', { ...props.filters, service_window: null });

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const money = (baht: string | undefined) => (Number(baht ?? 0) === 0 ? '-' : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('summary.projects_title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('summary.projects_title')" :description="t('summary.projects_description')" />

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('summary.search_projects')" />
                <select v-model="filters.customer_id" :class="selectClass" :aria-label="t('summary.customer')">
                    <option :value="null">{{ t('summary.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <select v-model="filters.phase" :class="selectClass" :aria-label="t('summary.phase')">
                    <option :value="null">{{ t('summary.all_phases') }}</option>
                    <option v-for="phase in phases" :key="phase" :value="phase">{{ t(`contracts.phases.${phase}`) }}</option>
                </select>
                <select v-model="filters.items" :class="selectClass" :aria-label="t('summary.items.with')">
                    <option v-for="key in ['with', 'all']" :key="key" :value="key">{{ t(`summary.items.${key}`) }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('contract_no')">
                                    {{ t('summary.project') }}
                                    <template v-if="filters.sort === 'contract_no'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('summary.customer') }}</th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('ends_on')">
                                    {{ t('summary.period') }}
                                    <template v-if="filters.sort === 'ends_on'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('summary.issues') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('summary.loans') }}</th>
                            <template v-if="showsPurchases">
                                <th class="px-4 py-2 text-right font-medium">{{ t('summary.purchases') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('summary.purchase_amount') }}</th>
                            </template>
                            <th class="px-4 py-2 text-right font-medium">{{ t('summary.open') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in projects.data" :key="row.id" class="border-t align-top">
                            <td class="px-4 py-2">
                                <Link
                                    :href="route('reporting.projects.show', row.id)"
                                    class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                >
                                    {{ row.contract_no }}
                                </Link>
                                <div class="font-medium">{{ row.title }}</div>
                            </td>
                            <td class="px-4 py-2">{{ row.customer ?? '-' }}</td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <div>{{ row.starts_on }} – {{ row.ends_on }}</div>
                                <ContractPhaseBadge :phase="row.phase" />
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                {{ row.issues
                                }}<span v-if="row.issues_open" class="text-xs text-amber-700 dark:text-amber-400"> ({{ row.issues_open }})</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                {{ row.loans
                                }}<span v-if="row.loans_open" class="text-xs text-amber-700 dark:text-amber-400"> ({{ row.loans_open }})</span>
                            </td>
                            <template v-if="showsPurchases">
                                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                    {{ row.purchases
                                    }}<span v-if="row.purchases_open" class="text-xs text-amber-700 dark:text-amber-400">
                                        ({{ row.purchases_open }})</span
                                    >
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ money(row.purchase_amount) }}</td>
                            </template>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">{{ row.open_count }}</td>
                        </tr>
                        <tr v-if="projects.data.length === 0">
                            <td :colspan="showsPurchases ? 8 : 6" class="px-4 py-8 text-center text-muted-foreground">
                                {{ t('summary.no_projects') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="projects" />
        </div>
    </AppLayout>
</template>
