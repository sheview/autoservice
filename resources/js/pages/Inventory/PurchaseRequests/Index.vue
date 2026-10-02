<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import PurchaseStatusBadge from '@/components/PurchaseStatusBadge.vue';
import StepDots from '@/components/StepDots.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { purchaseSteps } from '@/lib/purchaseSteps';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import type { PurchaseRequestRow } from '@/types/purchase';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ExternalLink, ShoppingCart } from 'lucide-vue-next';

interface Filters {
    search: string;
    // A work queue (tab) instead of the status filter.
    queue: string | null;
    status: string;
    mine: boolean;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    requests: Paginated<PurchaseRequestRow>;
    filters: Filters;
    statuses: string[];
    // The user's work queues with how many wait in each; none = no tabs.
    queues: Record<string, number>;
    can: { create: boolean; viewAll: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('purchase_requests.title'), href: route('inventory.purchase-requests.index') }];
const filters = useListFilters('inventory.purchase-requests.index', { ...props.filters, mine: props.filters.mine || null });

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
};

const money = (baht: string | null) => (baht === null ? '-' : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('purchase_requests.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('purchase_requests.title')" :description="t('purchase_requests.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('inventory.purchase-requests.create')">
                        <ShoppingCart class="h-4 w-4" />
                        {{ t('purchase_requests.create') }}
                    </Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <nav v-if="Object.keys(queues).length" class="flex flex-wrap gap-1 border-b">
                <button
                    v-for="key in [null, ...Object.keys(queues)]"
                    :key="key ?? 'all'"
                    type="button"
                    class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm"
                    :class="filters.queue === key ? 'border-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground'"
                    @click="filters.queue = key"
                >
                    {{ t(`purchase_requests.queues.${key ?? 'all'}`) }}
                    <span v-if="key && queues[key]" class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground">{{ queues[key] }}</span>
                </button>
            </nav>

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('purchase_requests.search_placeholder')" />
                <select v-if="!filters.queue" v-model="filters.status" :class="selectClass" :aria-label="t('purchase_requests.status')">
                    <option v-for="key in ['open', 'all']" :key="key" :value="key">{{ t(`purchase_requests.status_filters.${key}`) }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`purchase_requests.statuses.${status}`) }}</option>
                </select>
                <label v-if="can.viewAll" class="flex items-center gap-2 text-sm">
                    <input v-model="filters.mine" type="checkbox" :true-value="true" :false-value="null" class="size-4 rounded border-input" />
                    {{ t('purchase_requests.mine') }}
                </label>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('pr_no')">
                                    {{ t('purchase_requests.pr_no') }}
                                    <template v-if="filters.sort === 'pr_no'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('purchase_requests.item_name') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('purchase_requests.total') }}</th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('created_at')">
                                    {{ t('purchase_requests.requested_at') }}
                                    <template v-if="filters.sort === 'created_at'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy('needed_by')">
                                    {{ t('purchase_requests.needed_by') }}
                                    <template v-if="filters.sort === 'needed_by'">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" /><ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('purchase_requests.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in requests.data" :key="row.ulid" class="border-t align-top">
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                <Link
                                    :href="route('inventory.purchase-requests.show', row.ulid)"
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ row.pr_no }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ row.item_name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ row.quantity }} {{ row.unit }}
                                    <span v-if="row.links.length" class="ml-1 inline-flex items-center gap-0.5"
                                        ><ExternalLink class="h-3 w-3" /> {{ row.links.length }}</span
                                    >
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ money(row.total) }}</td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <div>{{ dateTime(row.requested_at) }}</div>
                                <div class="text-xs text-muted-foreground">{{ row.requested_by_name }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">{{ row.needed_by ?? '-' }}</td>
                            <td class="px-4 py-2">
                                <StepDots v-bind="purchaseSteps(row)" />
                                <div class="mt-1"><PurchaseStatusBadge :status="row.status" /></div>
                            </td>
                        </tr>
                        <tr v-if="requests.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ t('purchase_requests.no_requests') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="requests" />
        </div>
    </AppLayout>
</template>
