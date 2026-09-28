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
import { computed } from 'vue';

interface AssetRow {
    ulid: string;
    asset_code: string;
    name: string;
    brand_model: string | null;
    serial_number: string | null;
    category: string | null;
    branch: string | null;
    customer: string | null;
    status: string;
    warranty_expires_at: string | null;
}

interface Filters {
    search: string;
    branch_id: number | null;
    customer_id: number | null;
    category_id: number | null;
    status: string | null;
    warranty: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    assets: Paginated<AssetRow>;
    filters: Filters;
    branches: { id: number; name: string }[];
    customers: { id: number; code: string; name: string }[];
    categories: { id: number; name: string }[];
    statuses: string[];
    expiringDays: number;
    can: { create: boolean; import: boolean; export: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('assets.title'), href: route('asset.assets.index') }];

const filters = useListFilters('asset.assets.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

// Export what is on screen: the same filters and sort, without the page number.
const exportUrl = computed(() => {
    const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== null && value !== ''));
    return route('asset.assets.export', query);
});

const columns = [
    { key: 'asset_code', sortable: true },
    { key: 'name', sortable: true },
    { key: 'category', sortable: false },
    { key: 'branch', sortable: false },
    // Customers exist only when the contract module is on.
    ...(props.customers.length ? [{ key: 'customer', sortable: false }] : []),
    { key: 'status', sortable: true },
    { key: 'warranty_expires_at', sortable: true },
];

// Local date as YYYY-MM-DD (toISOString would give the UTC date).
const today = new Date().toLocaleDateString('sv-SE');

const statusClass: Record<string, string> = {
    in_use: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    spare: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    in_repair: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    retired: 'bg-muted text-muted-foreground',
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('assets.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('assets.title')" :description="t('assets.description')" />
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.export" variant="outline" as-child>
                        <a :href="exportUrl">{{ t('assets.export') }}</a>
                    </Button>
                    <Button v-if="can.import" variant="outline" as-child>
                        <Link :href="route('asset.imports.index')">{{ t('assets.import') }}</Link>
                    </Button>
                    <Button v-if="can.create" as-child>
                        <Link :href="route('asset.assets.create')">{{ t('assets.create') }}</Link>
                    </Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('assets.search_placeholder')" />
                <select v-model="filters.category_id" :class="selectClass" :aria-label="t('assets.category')">
                    <option :value="null">{{ t('assets.all_categories') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
                <select v-if="branches.length > 1" v-model="filters.branch_id" :class="selectClass" :aria-label="t('assets.branch')">
                    <option :value="null">{{ t('assets.all_branches') }}</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                </select>
                <select v-if="customers.length" v-model="filters.customer_id" :class="selectClass" :aria-label="t('assets.customer')">
                    <option :value="null">{{ t('assets.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <select v-model="filters.status" :class="selectClass" :aria-label="t('assets.status')">
                    <option :value="null">{{ t('assets.all_statuses') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`assets.statuses.${status}`) }}</option>
                </select>
                <select v-model="filters.warranty" :class="selectClass" :aria-label="t('assets.warranty')">
                    <option :value="null">{{ t('assets.all_warranty') }}</option>
                    <option v-for="key in ['active', 'expiring', 'expired', 'none']" :key="key" :value="key">
                        {{ t(`assets.warranty_filters.${key}`, { days: expiringDays }) }}
                    </option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column.key" class="whitespace-nowrap px-4 py-2 font-medium">
                                <button v-if="column.sortable" type="button" class="flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(`assets.${column.key}`) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(`assets.${column.key}`) }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="asset in assets.data" :key="asset.ulid" class="border-t">
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                <Link :href="route('asset.assets.show', asset.ulid)" class="text-primary underline-offset-4 hover:underline">
                                    {{ asset.asset_code }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ asset.name }}</div>
                                <div v-if="asset.brand_model || asset.serial_number" class="text-xs text-muted-foreground">
                                    {{ [asset.brand_model, asset.serial_number && `S/N ${asset.serial_number}`].filter(Boolean).join(' · ') }}
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ asset.category ?? t('common.none') }}</td>
                            <td class="px-4 py-2">{{ asset.branch ?? t('assets.no_branch') }}</td>
                            <td v-if="customers.length" class="px-4 py-2">{{ asset.customer ?? t('common.none') }}</td>
                            <td class="px-4 py-2">
                                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="statusClass[asset.status]">
                                    {{ t(`assets.statuses.${asset.status}`) }}
                                </span>
                            </td>
                            <td
                                class="whitespace-nowrap px-4 py-2"
                                :class="{ 'text-red-700 dark:text-red-400': asset.warranty_expires_at && asset.warranty_expires_at < today }"
                            >
                                {{ asset.warranty_expires_at ?? t('common.none') }}
                            </td>
                        </tr>
                        <tr v-if="assets.data.length === 0">
                            <td :colspan="columns.length" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="assets" />
        </div>
    </AppLayout>
</template>
