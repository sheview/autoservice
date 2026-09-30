<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface PartRow {
    id: number;
    code: string;
    name: string;
    part_number: string | null;
    brand: string | null;
    unit: string;
    min_qty: number;
    qty_on_hand: number;
    unit_cost: string | null;
    is_active: boolean;
    low: boolean;
}

interface Filters {
    search: string;
    status: string | null;
    stock: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    parts: Paginated<PartRow>;
    filters: Filters;
    statuses: string[];
    stockLevels: string[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('parts.title'), href: route('inventory.parts.index') }];

const filters = useListFilters('inventory.parts.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const columns = [
    { key: 'code', sortable: true, right: false },
    { key: 'name', sortable: true, right: false },
    { key: 'qty_on_hand', sortable: true, right: true },
    { key: 'min_qty', sortable: false, right: true },
    { key: 'unit_cost', sortable: true, right: true },
];

const money = (baht: string | null) => (baht === null ? t('common.none') : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));

const destroy = (part: PartRow) => {
    if (confirm(t('common.confirm_delete', { name: part.name }))) {
        router.delete(route('inventory.parts.destroy', part.id), { preserveScroll: true });
    }
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('parts.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('parts.title')" :description="t('parts.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('inventory.parts.create')">{{ t('parts.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="(page.props.errors as Record<string, string>).part" />

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('parts.search_placeholder')" />
                <select v-model="filters.status" :class="selectClass" :aria-label="t('common.status')">
                    <option :value="null">{{ t('parts.all_statuses') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`parts.statuses.${status}`) }}</option>
                </select>
                <select v-model="filters.stock" :class="selectClass" :aria-label="t('parts.qty_on_hand')">
                    <option :value="null">{{ t('parts.all_stock_levels') }}</option>
                    <option v-for="level in stockLevels" :key="level" :value="level">{{ t(`parts.stock_levels.${level}`) }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                class="whitespace-nowrap px-4 py-2 font-medium"
                                :class="{ 'text-right': column.right }"
                            >
                                <button v-if="column.sortable" type="button" class="inline-flex items-center gap-1" @click="sortBy(column.key)">
                                    {{ t(`parts.${column.key}`) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(`parts.${column.key}`) }}</span>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('common.status') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="part in parts.data" :key="part.id" class="border-t">
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                <Link :href="route('inventory.parts.show', part.id)" class="text-primary underline-offset-4 hover:underline">
                                    {{ part.code }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ part.name }}</div>
                                <div v-if="part.brand || part.part_number" class="text-xs text-muted-foreground">
                                    {{ [part.brand, part.part_number].filter(Boolean).join(' · ') }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <span
                                    v-if="part.qty_on_hand === 0"
                                    class="mr-2 rounded bg-red-100 px-1 text-xs text-red-800 dark:bg-red-950 dark:text-red-200"
                                >
                                    {{ t('parts.out') }}
                                </span>
                                <span
                                    v-else-if="part.low"
                                    class="mr-2 rounded bg-amber-100 px-1 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                                >
                                    {{ t('parts.low') }}
                                </span>
                                <span class="font-medium">{{ part.qty_on_hand.toLocaleString('th-TH') }}</span>
                                <span class="text-muted-foreground"> {{ part.unit }}</span>
                            </td>
                            <td class="px-4 py-2 text-right text-muted-foreground">
                                {{ part.min_qty > 0 ? part.min_qty.toLocaleString('th-TH') : t('common.none') }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">{{ money(part.unit_cost) }}</td>
                            <td class="px-4 py-2">
                                <span :class="part.is_active ? '' : 'text-muted-foreground'">
                                    {{ t(`parts.statuses.${part.is_active ? 'active' : 'inactive'}`) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <Link
                                    v-if="can.update"
                                    :href="route('inventory.parts.edit', part.id)"
                                    class="text-primary underline-offset-4 hover:underline"
                                >
                                    {{ t('common.edit') }}
                                </Link>
                                <button
                                    v-if="can.delete && part.qty_on_hand === 0"
                                    type="button"
                                    class="ml-3 text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                                    @click="destroy(part)"
                                >
                                    {{ t('common.delete') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="parts.data.length === 0">
                            <td :colspan="columns.length + 2" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="parts" />
        </div>
    </AppLayout>
</template>
