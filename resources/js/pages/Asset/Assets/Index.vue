<script setup lang="ts">
import AssetStatusBadge from '@/components/AssetStatusBadge.vue';
import BarcodeScanButton from '@/components/BarcodeScanButton.vue';
import Heading from '@/components/Heading.vue';
import ListThumb from '@/components/ListThumb.vue';
import Pagination from '@/components/Pagination.vue';
import StatTile from '@/components/StatTile.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Boxes, CircleCheck, ShieldAlert, Trash2, Wrench } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface AssetRow {
    ulid: string;
    asset_code: string;
    name: string;
    brand_model: string | null;
    serial_number: string | null;
    property_no: string | null;
    category: string | null;
    location: string | null;
    floor: string | null;
    room: string | null;
    customer: string | null;
    used_by: string | null;
    department: string | null;
    // Who has it now: handed out on a loan/issue form and not back.
    holders: { name: string; type: 'loan' | 'issue'; qty: number; due_return_date: string | null; request_ulid: string }[];
    status: string;
    warranty_expires_at: string | null;
    // Available = not held by an issue/loan form that is asked for or out.
    quantity: number;
    available: number;
    unit: string | null;
    // The main photo, small (null: none).
    photo: string | null;
}

interface Filters {
    search: string;
    branch_id: number | null;
    customer_id: number | null;
    category_id: number | null;
    storage_location_id: number | null;
    asset_type: string | null;
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
    categories: { id: number; name: string; asset_type: string }[];
    locations: { id: number; name: string }[];
    assetTypes: string[];
    statuses: string[];
    expiringDays: number;
    stats: { total: number; in_use: number; in_repair: number; warranty_expiring: number };
    can: { create: boolean; import: boolean; export: boolean; bulkDelete: boolean; lists: boolean };
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
    { key: 'location', sortable: true },
    // Customers exist only when the contract module is on.
    ...(props.customers.length ? [{ key: 'customer', sortable: false }] : []),
    { key: 'user', sortable: false },
    { key: 'quantity', sortable: false },
    { key: 'status', sortable: true },
    { key: 'warranty_expires_at', sortable: true },
];

// --- Ticked rows, deleted at once (admin): kept across pages and filters until cleared -------
const selected = ref<string[]>([]);
const pageUlids = computed(() => props.assets.data.map((a) => a.ulid));
const allOnPage = computed(() => pageUlids.value.length > 0 && pageUlids.value.every((u) => selected.value.includes(u)));
const toggle = (ulid: string) => {
    selected.value = selected.value.includes(ulid) ? selected.value.filter((u) => u !== ulid) : [...selected.value, ulid];
};
const togglePage = () => {
    selected.value = allOnPage.value
        ? selected.value.filter((u) => !pageUlids.value.includes(u))
        : [...new Set([...selected.value, ...pageUlids.value])];
};
const deleting = ref(false);
const deleteSelected = () => {
    if (!confirm(t('assets.bulk_delete_confirm', { count: selected.value.length }))) return;
    router.post(
        route('asset.assets.bulk-destroy'),
        { assets: selected.value },
        {
            preserveScroll: true,
            onStart: () => (deleting.value = true),
            onSuccess: () => (selected.value = []),
            onFinish: () => (deleting.value = false),
        },
    );
};

// Local date as YYYY-MM-DD (toISOString would give the UTC date).
const today = new Date().toLocaleDateString('sv-SE');

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('assets.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('assets.title')" :description="t('assets.description')" />
                <div class="flex flex-wrap gap-2">
                    <!-- The shared lists behind the drop-downs (Settings) -->
                    <Button v-if="can.lists" variant="outline" as-child>
                        <Link :href="route('asset.categories.index')">{{ t('nav.asset_categories') }}</Link>
                    </Button>
                    <Button v-if="can.lists" variant="outline" as-child>
                        <Link :href="route('asset.locations.index')">{{ t('nav.storage_locations') }}</Link>
                    </Button>
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

            <!-- Click a figure to list those assets -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile :label="t('assets.stats.total')" :value="stats.total" :icon="Boxes" tone="violet" :href="route('asset.assets.index')" />
                <StatTile
                    :label="t('assets.stats.in_use')"
                    :value="stats.in_use"
                    :icon="CircleCheck"
                    tone="emerald"
                    :href="route('asset.assets.index', { status: 'in_use' })"
                />
                <StatTile
                    :label="t('assets.stats.in_repair')"
                    :value="stats.in_repair"
                    :icon="Wrench"
                    tone="orange"
                    :href="route('asset.assets.index', { status: 'in_repair' })"
                />
                <StatTile
                    :label="t('assets.stats.warranty_expiring', { days: expiringDays })"
                    :value="stats.warranty_expiring"
                    :icon="ShieldAlert"
                    tone="amber"
                    :href="route('asset.assets.index', { warranty: 'expiring' })"
                />
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap gap-3">
                <div class="flex w-full max-w-sm gap-2">
                    <Input v-model="filters.search" type="search" class="min-w-0 flex-1" :placeholder="t('assets.search_placeholder')" />
                    <!-- A serial, part number or QR read by the camera becomes the search -->
                    <BarcodeScanButton :hint="t('scan_camera.hint_search')" @scanned="(text) => (filters.search = text)" />
                </div>
                <select v-model="filters.asset_type" :class="selectClass" :aria-label="t('asset_categories.asset_type')">
                    <option :value="null">{{ t('asset_categories.all_asset_types') }}</option>
                    <option v-for="type in assetTypes" :key="type" :value="type">{{ t(`asset_categories.asset_types.${type}`) }}</option>
                </select>
                <select v-model="filters.category_id" :class="selectClass" :aria-label="t('assets.category')">
                    <option :value="null">{{ t('assets.all_categories') }}</option>
                    <option
                        v-for="category in categories.filter((c) => !filters.asset_type || c.asset_type === filters.asset_type)"
                        :key="category.id"
                        :value="category.id"
                    >
                        {{ category.name }}
                    </option>
                </select>
                <select v-if="locations.length" v-model="filters.storage_location_id" :class="selectClass" :aria-label="t('storage_locations.field')">
                    <option :value="null">{{ t('storage_locations.all') }}</option>
                    <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
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

            <div
                v-if="can.bulkDelete && selected.length"
                class="flex flex-wrap items-center gap-2 rounded-md border border-red-200 bg-red-50 px-4 py-2 dark:border-red-900 dark:bg-red-950"
            >
                <span class="text-sm font-medium">{{ t('assets.selected', { count: selected.length }) }}</span>
                <Button variant="ghost" size="sm" @click="selected = []">{{ t('assets.clear_selection') }}</Button>
                <Button variant="destructive" size="sm" class="ml-auto" :disabled="deleting" @click="deleteSelected">
                    <Trash2 class="h-4 w-4" />
                    {{ t('assets.bulk_delete') }}
                </Button>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-if="can.bulkDelete" class="w-10 px-4 py-2">
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="allOnPage"
                                    :aria-label="t('assets.select_page')"
                                    @change="togglePage"
                                />
                            </th>
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
                        <tr
                            v-for="asset in assets.data"
                            :key="asset.ulid"
                            class="border-t"
                            :class="{ 'bg-red-50/60 dark:bg-red-950/40': selected.includes(asset.ulid) }"
                        >
                            <td v-if="can.bulkDelete" class="px-4 py-2">
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="selected.includes(asset.ulid)"
                                    :aria-label="t('assets.select_row', { code: asset.asset_code })"
                                    @change="toggle(asset.ulid)"
                                />
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                <div class="flex items-center gap-3">
                                    <ListThumb :src="asset.photo" :alt="asset.name" />
                                    <div>
                                        <Link :href="route('asset.assets.show', asset.ulid)" class="text-primary underline-offset-4 hover:underline">
                                            {{ asset.asset_code }}
                                        </Link>
                                        <div v-if="asset.property_no" class="text-muted-foreground">
                                            {{ t('assets.property_no_short') }} {{ asset.property_no }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ asset.name }}</div>
                                <div v-if="asset.brand_model || asset.serial_number" class="text-xs text-muted-foreground">
                                    {{ [asset.brand_model, asset.serial_number && `S/N ${asset.serial_number}`].filter(Boolean).join(' · ') }}
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ asset.category ?? t('common.none') }}</td>
                            <td class="px-4 py-2">
                                <div>{{ asset.location ?? t('common.none') }}</div>
                                <div v-if="asset.floor || asset.room" class="text-xs text-muted-foreground">
                                    {{
                                        [
                                            asset.floor && t('assets.floor_short', { floor: asset.floor }),
                                            asset.room && t('assets.room_short', { room: asset.room }),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </div>
                            </td>
                            <td v-if="customers.length" class="px-4 py-2">{{ asset.customer ?? t('common.none') }}</td>
                            <td class="px-4 py-2">
                                <div v-if="asset.used_by">{{ asset.used_by }}</div>
                                <div v-if="asset.department" class="text-xs text-muted-foreground">{{ asset.department }}</div>
                                <Link
                                    v-for="holder in asset.holders.slice(0, 2)"
                                    :key="holder.request_ulid + holder.name"
                                    :href="route('asset.requests.show', holder.request_ulid)"
                                    class="block text-xs text-amber-700 underline-offset-4 hover:underline dark:text-amber-400"
                                >
                                    {{ t(`assets.holder_${holder.type}`, { name: holder.name })
                                    }}<template v-if="holder.qty > 1"> ({{ holder.qty }})</template>
                                    <span v-if="holder.due_return_date" :class="{ 'text-red-700 dark:text-red-400': holder.due_return_date < today }">
                                        · {{ t('assets.holder_due', { date: holder.due_return_date }) }}</span
                                    >
                                </Link>
                                <div v-if="asset.holders.length > 2" class="text-xs text-muted-foreground">
                                    {{ t('assets.holders_more', { count: asset.holders.length - 2 }) }}
                                </div>
                                <span v-if="!asset.used_by && !asset.department && asset.holders.length === 0">{{ t('common.none') }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2" :title="t('assets.quantity_hint')">
                                <span class="font-medium" :class="{ 'text-red-700 dark:text-red-400': asset.available === 0 }">{{
                                    asset.available
                                }}</span>
                                <span class="text-muted-foreground">/{{ asset.quantity }}</span>
                                <span v-if="asset.unit" class="text-xs text-muted-foreground"> {{ asset.unit }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <AssetStatusBadge :status="asset.status" />
                            </td>
                            <td
                                class="whitespace-nowrap px-4 py-2"
                                :class="{ 'text-red-700 dark:text-red-400': asset.warranty_expires_at && asset.warranty_expires_at < today }"
                            >
                                {{ asset.warranty_expires_at ?? t('common.none') }}
                            </td>
                        </tr>
                        <tr v-if="assets.data.length === 0">
                            <td :colspan="columns.length + (can.bulkDelete ? 1 : 0)" class="px-4 py-8 text-center text-muted-foreground">
                                {{ t('common.no_results') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="assets" />
        </div>
    </AppLayout>
</template>
