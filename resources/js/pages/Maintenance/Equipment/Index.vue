<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import PmResultBadge from '@/components/PmResultBadge.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface EquipmentRow {
    id: number;
    ulid: string;
    asset_code: string;
    name: string;
    category: string | null;
    customer: string | null;
    serial_number: string | null;
    location: string | null;
    pm: {
        last: { visit_no: string; ulid: string; date: string | null; result: string } | null;
        next: { visit_no: string; ulid: string; date: string; overdue: boolean } | null;
        done_count: number;
    } | null;
}

interface Filters {
    search: string;
    customer_id: number | null;
    category_id: number | null;
    pm: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    assets: Paginated<EquipmentRow>;
    filters: Filters;
    customers: { id: number; name: string }[];
    categories: { id: number; name: string }[];
    pmFilters: string[];
    counts: Record<string, number>;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_equipment.title'), href: route('maintenance.equipment.index') }];
const filters = useListFilters('maintenance.equipment.index', props.filters);
const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_equipment.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('pm_equipment.title')" :description="t('pm_equipment.description')" />

            <!-- Quick filters by where the PM stands -->
            <div class="grid grid-cols-3 gap-3 sm:max-w-xl">
                <button
                    v-for="key in pmFilters"
                    :key="key"
                    type="button"
                    class="rounded-lg border p-3 text-left transition hover:bg-muted/50"
                    :class="{
                        'ring-2 ring-primary': filters.pm === key,
                        'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40': key === 'overdue' && counts[key] > 0,
                    }"
                    @click="filters.pm = filters.pm === key ? null : key"
                >
                    <div class="text-sm text-muted-foreground">{{ t(`pm_equipment.filters.${key}`) }}</div>
                    <div
                        class="text-2xl font-semibold tabular-nums"
                        :class="{ 'text-red-700 dark:text-red-400': key === 'overdue' && counts[key] > 0 }"
                    >
                        {{ counts[key] }}
                    </div>
                </button>
            </div>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('pm_equipment.search_placeholder')" />
                <select v-model="filters.customer_id" :class="selectClass">
                    <option :value="null">{{ t('pm_visits.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <select v-model="filters.category_id" :class="selectClass">
                    <option :value="null">{{ t('pm_equipment.all_categories') }}</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="inline-flex items-center gap-1" @click="sortBy('asset_code')">
                                    {{ t('pm_equipment.asset') }}
                                    <component
                                        :is="filters.direction === 'asc' ? ArrowUp : ArrowDown"
                                        v-if="filters.sort === 'asset_code'"
                                        class="h-3 w-3"
                                    />
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_equipment.category') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_visits.customer') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_equipment.last') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_equipment.next') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="asset in assets.data" :key="asset.id" class="border-t align-top">
                            <td class="px-4 py-2">
                                <Link
                                    :href="route('asset.assets.show', asset.ulid)"
                                    class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                    >{{ asset.asset_code }}</Link
                                >
                                <div>{{ asset.name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ [asset.serial_number, asset.location].filter(Boolean).join(' · ') }}
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ asset.category ?? t('common.none') }}</td>
                            <td class="px-4 py-2">{{ asset.customer ?? t('common.none') }}</td>
                            <td class="px-4 py-2">
                                <template v-if="asset.pm?.last">
                                    <Link
                                        :href="route('maintenance.visits.show', asset.pm.last.ulid)"
                                        class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                        >{{ asset.pm.last.visit_no }}</Link
                                    >
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-muted-foreground">{{ asset.pm.last.date }}</span>
                                        <PmResultBadge :result="asset.pm.last.result" />
                                    </div>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">{{ t('pm_equipment.never') }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <template v-if="asset.pm?.next">
                                    <Link
                                        :href="route('maintenance.visits.show', asset.pm.next.ulid)"
                                        class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                        >{{ asset.pm.next.visit_no }}</Link
                                    >
                                    <div
                                        class="text-xs"
                                        :class="asset.pm.next.overdue ? 'font-semibold text-red-700 dark:text-red-400' : 'text-muted-foreground'"
                                    >
                                        {{ asset.pm.next.date }}<template v-if="asset.pm.next.overdue"> · {{ t('pm_visits.overdue') }}</template>
                                    </div>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">{{ t('pm_equipment.none_planned') }}</span>
                            </td>
                        </tr>
                        <tr v-if="assets.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="assets" />
        </div>
    </AppLayout>
</template>
