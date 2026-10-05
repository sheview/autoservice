<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import SerialHits, { type SerialHit } from '@/components/SerialHits.vue';
import RequestStatusBadge from '@/components/RequestStatusBadge.vue';
import RequestSteps from '@/components/RequestSteps.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import type { CheckoutLineRequestRef, CheckoutLineRow, CheckoutRequestRow } from '@/types/checkout';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, HandHelping } from 'lucide-vue-next';
import { computed } from 'vue';

type Tab = 'requests' | 'approve' | 'fulfill' | 'backorders' | 'returns';

interface Filters {
    tab: Tab;
    search: string;
    status: string;
    overdue: boolean;
    sort: string;
    direction: string;
    [key: string]: unknown;
}

const props = defineProps<{
    filters: Filters;
    // One of the two, by tab: requests (requests / approve / fulfill) or lines (backorders / returns).
    requests: Paginated<CheckoutRequestRow> | null;
    lines: Paginated<CheckoutLineRow & { request: CheckoutLineRequestRef }> | null;
    // null = the user has no such queue.
    counts: { approve: number | null; fulfill: number | null; backorders: number | null; returns: number | null };
    statuses: string[];
    serialHits: SerialHit[];
    can: { view: boolean; create: boolean; forOthers: boolean; approve: boolean; fulfill: boolean; return: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('requests.title'), href: route('asset.requests.index') }];
const filters = useListFilters('asset.requests.index', props.filters);

const tabs = computed(() =>
    (
        [
            { key: 'requests', show: true, count: null },
            { key: 'approve', show: props.can.approve, count: props.counts.approve },
            { key: 'fulfill', show: props.can.fulfill, count: props.counts.fulfill },
            { key: 'backorders', show: props.can.fulfill, count: props.counts.backorders },
            { key: 'returns', show: props.can.return, count: props.counts.returns },
        ] as { key: Tab; show: boolean; count: number | null }[]
    ).filter((tab) => tab.show),
);

// Each tab has its own default order: the server picks it when none is sent.
const switchTab = (tab: Tab) => {
    if (filters.tab === tab) return;
    filters.tab = tab;
    filters.sort = '';
    filters.direction = '';
    filters.overdue = false;
};

const sortBy = (column: string) => {
    filters.direction = props.filters.sort === column && props.filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const lineTab = computed(() => props.filters.tab === 'backorders' || props.filters.tab === 'returns');
const today = new Date().toLocaleDateString('sv-SE');
const late = (date: string | null) => date !== null && date < today;

const itemsSummary = (row: CheckoutRequestRow) => {
    const names = row.items.slice(0, 2).map((item) => item.item_name);
    const more = row.items.length - names.length;
    return more > 0 ? `${names.join(', ')} ${t('requests.more', { count: more })}` : names.join(', ');
};

const linkClass = 'font-mono text-xs text-primary underline-offset-4 hover:underline';
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('requests.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('requests.title')" :description="t('requests.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('asset.requests.create')">
                        <HandHelping class="h-4 w-4" />
                        {{ t('requests.new') }}
                    </Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p v-if="page.props.flash.error" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </p>

            <SerialHits :hits="serialHits" />

            <nav v-if="tabs.length > 1" class="flex flex-wrap gap-1 border-b" :aria-label="t('requests.tabs_label')">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm"
                    :class="
                        filters.tab === tab.key ? 'border-primary font-semibold' : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="filters.tab === tab.key ? 'page' : undefined"
                    @click="switchTab(tab.key)"
                >
                    {{ t(`requests.tabs.${tab.key}`) }}
                    <span
                        v-if="tab.count"
                        class="rounded-full px-1.5 text-xs"
                        :class="tab.key === 'returns' ? 'bg-red-600 text-white' : 'bg-primary text-primary-foreground'"
                        :title="tab.key === 'returns' ? t('requests.overdue_count') : undefined"
                        >{{ tab.count }}</span
                    >
                </button>
            </nav>

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('requests.search_placeholder')" />
                <select v-if="filters.tab === 'requests'" v-model="filters.status" :class="selectClass" :aria-label="t('requests.status')">
                    <option value="open">{{ t('requests.status_filters.open') }}</option>
                    <option value="all">{{ t('requests.status_filters.all') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`requests.statuses.${status}`) }}</option>
                </select>
                <label v-if="filters.tab === 'returns'" class="flex items-center gap-2 text-sm">
                    <input v-model="filters.overdue" type="checkbox" />
                    {{ t('requests.overdue_only') }}
                </label>
            </div>

            <!-- Requests -->
            <template v-if="!lineTab && requests">
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th
                                    v-for="column in ['request_no', 'borrower', 'items', 'created_at', 'needed_by', 'status']"
                                    :key="column"
                                    class="px-4 py-2 font-medium"
                                >
                                    <button
                                        v-if="['request_no', 'created_at', 'needed_by'].includes(column)"
                                        type="button"
                                        class="flex items-center gap-1"
                                        @click="sortBy(column)"
                                    >
                                        {{ t(`requests.columns.${column}`) }}
                                        <template v-if="props.filters.sort === column">
                                            <ArrowUp v-if="props.filters.direction === 'asc'" class="h-3 w-3" />
                                            <ArrowDown v-else class="h-3 w-3" />
                                        </template>
                                    </button>
                                    <span v-else>{{ t(`requests.columns.${column}`) }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in requests.data" :key="row.ulid" class="border-t align-top">
                                <td class="whitespace-nowrap px-4 py-2">
                                    <Link :href="route('asset.requests.show', row.ulid)" :class="linkClass">{{ row.request_no }}</Link>
                                </td>
                                <td class="px-4 py-2">
                                    <div>{{ row.borrower_name }}</div>
                                    <div v-if="row.borrower_department" class="text-xs text-muted-foreground">{{ row.borrower_department }}</div>
                                </td>
                                <td class="px-4 py-2">
                                    <div>{{ itemsSummary(row) }}</div>
                                    <div class="text-xs text-muted-foreground">{{ t('requests.lines_count', { count: row.items.length }) }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2">
                                    <div>{{ dateTime(row.requested_at) }}</div>
                                    <div class="text-xs text-muted-foreground">{{ row.requester_name }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2">{{ row.needed_by ?? '-' }}</td>
                                <td class="px-4 py-2">
                                    <RequestSteps :request="row" compact />
                                    <div class="mt-1"><RequestStatusBadge :status="row.status" /></div>
                                </td>
                            </tr>
                            <tr v-if="requests.data.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ t('requests.no_requests') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="requests" />
            </template>

            <!-- Lines: backorders / returns -->
            <template v-if="lineTab && lines">
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('requests.columns.item') }}</th>
                                <th class="px-4 py-2 text-right font-medium">
                                    {{ filters.tab === 'returns' ? t('requests.columns.outstanding') : t('requests.columns.remaining') }}
                                </th>
                                <th v-if="filters.tab === 'backorders'" class="px-4 py-2 text-right font-medium">
                                    {{ t('requests.columns.on_hand') }}
                                </th>
                                <th class="px-4 py-2 font-medium">{{ t('requests.columns.request') }}</th>
                                <th class="px-4 py-2 font-medium">
                                    <button type="button" class="flex items-center gap-1" @click="sortBy('needed_by')">
                                        {{ filters.tab === 'returns' ? t('requests.columns.due_return_date') : t('requests.columns.needed_by') }}
                                        <ArrowDown v-if="props.filters.direction === 'desc'" class="h-3 w-3" />
                                        <ArrowUp v-else class="h-3 w-3" />
                                    </button>
                                </th>
                                <th v-if="filters.tab === 'backorders'" class="px-4 py-2 font-medium">
                                    {{ t('requests.columns.purchase_request') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in lines.data" :key="line.id" class="border-t align-top">
                                <td class="px-4 py-2">
                                    <Link v-if="line.asset_ulid" :href="route('asset.assets.show', line.asset_ulid)" :class="linkClass">
                                        {{ line.item_code }}
                                    </Link>
                                    <div v-else class="font-mono text-xs">{{ line.item_code }}</div>
                                    <div>{{ line.item_name }}</div>
                                    <RequestStatusBadge :status="line.status" kind="line" :overdue="line.overdue" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    {{ filters.tab === 'returns' ? line.outstanding : line.remaining }} {{ line.unit ?? '' }}
                                </td>
                                <td v-if="filters.tab === 'backorders'" class="whitespace-nowrap px-4 py-2 text-right">{{ line.on_hand ?? '-' }}</td>
                                <td class="px-4 py-2">
                                    <Link :href="route('asset.requests.show', line.request.ulid)" :class="linkClass">{{
                                        line.request.request_no
                                    }}</Link>
                                    <div>{{ line.request.borrower_name }}</div>
                                </td>
                                <td
                                    v-if="filters.tab === 'returns'"
                                    class="whitespace-nowrap px-4 py-2"
                                    :class="{ 'text-red-700 dark:text-red-400': line.overdue }"
                                >
                                    {{ line.due_return_date ?? '-' }}
                                </td>
                                <td
                                    v-else
                                    class="whitespace-nowrap px-4 py-2"
                                    :class="{ 'text-red-700 dark:text-red-400': late(line.request.needed_by) }"
                                >
                                    {{ line.request.needed_by ?? '-' }}
                                </td>
                                <td v-if="filters.tab === 'backorders'" class="px-4 py-2">
                                    <Link
                                        v-if="line.purchase_request"
                                        :href="route('inventory.purchase-requests.show', line.purchase_request.ulid)"
                                        :class="linkClass"
                                    >
                                        {{ line.purchase_request.pr_no }}
                                    </Link>
                                    <span v-else class="text-muted-foreground">-</span>
                                </td>
                            </tr>
                            <tr v-if="lines.data.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ t('requests.no_lines') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="lines" />
            </template>
        </div>
    </AppLayout>
</template>
