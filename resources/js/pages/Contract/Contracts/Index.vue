<script setup lang="ts">
import ContractPhaseBadge from '@/components/ContractPhaseBadge.vue';
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

interface ContractRow {
    id: number;
    contract_no: string;
    title: string;
    customer: string | null;
    starts_on: string;
    ends_on: string;
    value: string | null;
    service_window: string;
    phase: string;
    assets_count: number;
}

interface Filters {
    search: string;
    customer_id: number | null;
    phase: string | null;
    service_window: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    contracts: Paginated<ContractRow>;
    filters: Filters;
    customers: { id: number; code: string; name: string }[];
    phases: string[];
    serviceWindows: string[];
    can: { create: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('contracts.title'), href: route('contract.contracts.index') }];

const filters = useListFilters('contract.contracts.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const columns = [
    { key: 'contract_no', label: 'contracts.contract_no', sortable: true },
    { key: 'title', label: 'contracts.title_field', sortable: true },
    { key: 'customer', label: 'contracts.customer', sortable: false },
    { key: 'ends_on', label: 'contracts.period', sortable: true },
    { key: 'value', label: 'contracts.value', sortable: true },
    { key: 'assets_count', label: 'contracts.assets_count', sortable: false },
    { key: 'phase', label: 'contracts.phase', sortable: false },
];

const money = (baht: string | null) => (baht === null ? t('common.none') : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('contracts.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('contracts.title')" :description="t('contracts.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('contract.contracts.create', filters.customer_id ? { customer_id: filters.customer_id } : {})">
                        {{ t('contracts.create') }}
                    </Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('contracts.search_placeholder')" />
                <select v-model="filters.customer_id" :class="selectClass" :aria-label="t('contracts.customer')">
                    <option :value="null">{{ t('contracts.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <select v-model="filters.phase" :class="selectClass" :aria-label="t('contracts.phase')">
                    <option :value="null">{{ t('contracts.all_phases') }}</option>
                    <option v-for="phase in phases" :key="phase" :value="phase">{{ t(`contracts.phases.${phase}`) }}</option>
                </select>
                <select v-model="filters.service_window" :class="selectClass" :aria-label="t('contracts.service_window')">
                    <option :value="null">{{ t('contracts.all_service_windows') }}</option>
                    <option v-for="window in serviceWindows" :key="window" :value="window">{{ window }}</option>
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
                        <tr v-for="contract in contracts.data" :key="contract.id" class="border-t">
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                <Link :href="route('contract.contracts.show', contract.id)" class="text-primary underline-offset-4 hover:underline">
                                    {{ contract.contract_no }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ contract.title }}</div>
                                <div class="text-xs text-muted-foreground">{{ contract.service_window }}</div>
                            </td>
                            <td class="px-4 py-2">{{ contract.customer ?? t('common.none') }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ contract.starts_on }} – {{ contract.ends_on }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">{{ money(contract.value) }}</td>
                            <td class="px-4 py-2 text-right">{{ contract.assets_count }}</td>
                            <td class="px-4 py-2"><ContractPhaseBadge :phase="contract.phase" /></td>
                        </tr>
                        <tr v-if="contracts.data.length === 0">
                            <td :colspan="columns.length" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="contracts" />
        </div>
    </AppLayout>
</template>
