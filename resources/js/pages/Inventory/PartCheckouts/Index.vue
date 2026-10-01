<script setup lang="ts">
import CheckoutActions from '@/components/CheckoutActions.vue';
import CheckoutStatusBadge from '@/components/CheckoutStatusBadge.vue';
import CheckoutSteps from '@/components/CheckoutSteps.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import type { CheckoutRow } from '@/types/checkout';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Package } from 'lucide-vue-next';

interface Filters {
    search: string;
    status: string;
    type: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

// Part issue/loan forms: the same rows and actions as an asset's (CheckoutRow with kind "part").
const props = defineProps<{
    checkouts: Paginated<CheckoutRow>;
    filters: Filters;
    statuses: string[];
    types: string[];
    can: { request: boolean; approve: boolean; userId: number };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('part_checkouts.title'), href: route('inventory.part-checkouts.index') }];
const filters = useListFilters('inventory.part-checkouts.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('part_checkouts.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('part_checkouts.title')" :description="t('part_checkouts.description')" />
                <Button v-if="can.request" as-child>
                    <Link :href="route('inventory.parts.index')">
                        <Package class="h-4 w-4" />
                        {{ t('part_checkouts.go_to_parts') }}
                    </Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p v-if="page.props.flash.error" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('part_checkouts.search_placeholder')" />
                <select v-model="filters.status" :class="selectClass" :aria-label="t('checkouts.status')">
                    <option v-for="key in ['open', 'overdue', 'all']" :key="key" :value="key">{{ t(`checkouts.status_filters.${key}`) }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`checkouts.statuses.${status}`) }}</option>
                </select>
                <select v-model="filters.type" :class="selectClass" :aria-label="t('checkouts.type')">
                    <option :value="null">{{ t('checkouts.all_types') }}</option>
                    <option v-for="type in types" :key="type" :value="type">{{ t(`part_checkouts.types.${type}`) }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th
                                v-for="column in ['checkout_no', 'part', 'borrower', 'created_at', 'due_on', 'status']"
                                :key="column"
                                class="px-4 py-2 font-medium"
                            >
                                <button
                                    v-if="['checkout_no', 'created_at', 'due_on'].includes(column)"
                                    type="button"
                                    class="flex items-center gap-1"
                                    @click="sortBy(column)"
                                >
                                    {{ t(`checkouts.${column === 'created_at' ? 'requested_at' : column}`) }}
                                    <template v-if="filters.sort === column">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ column === 'part' ? t('part_checkouts.part') : t(`checkouts.${column}`) }}</span>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in checkouts.data" :key="row.ulid" class="border-t align-top">
                            <td class="whitespace-nowrap px-4 py-2">
                                <div class="font-mono text-xs">{{ row.checkout_no }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ t(`part_checkouts.types.${row.type}`) }} · {{ row.quantity }} {{ row.asset?.unit ?? '' }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <Link
                                    v-if="row.asset"
                                    :href="route('inventory.parts.show', row.asset.ulid)"
                                    class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                >
                                    {{ row.asset.asset_code }}
                                </Link>
                                <div>{{ row.asset?.name }}</div>
                                <div v-if="row.asset?.serial_number" class="text-xs text-muted-foreground">{{ row.asset.serial_number }}</div>
                            </td>
                            <td class="px-4 py-2">
                                <div>{{ row.borrower_name }}</div>
                                <div v-if="row.borrower_department" class="text-xs text-muted-foreground">{{ row.borrower_department }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <div>{{ dateTime(row.requested_at) }}</div>
                                <div class="text-xs text-muted-foreground">{{ row.requested_by_name }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2" :class="{ 'text-red-700 dark:text-red-400': row.overdue }">
                                {{ row.due_on ?? '-' }}
                            </td>
                            <td class="px-4 py-2">
                                <CheckoutSteps :checkout="row" compact />
                                <div class="mt-1"><CheckoutStatusBadge :status="row.status" :overdue="row.overdue" /></div>
                            </td>
                            <td class="min-w-64 px-4 py-2"><CheckoutActions :checkout="row" :can="can" /></td>
                        </tr>
                        <tr v-if="checkouts.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ t('part_checkouts.no_checkouts') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="checkouts" />
        </div>
    </AppLayout>
</template>
