<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import StockMovementTypeBadge from '@/components/StockMovementTypeBadge.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface MovementRow {
    id: number;
    type: string;
    quantity: number;
    balance_after: number;
    reference: string | null;
    note: string | null;
    user_name: string | null;
    part: { id: number; code: string; name: string; unit: string; deleted: boolean } | null;
    ticket: { ulid: string; ticket_no: string; status: string } | null;
    at: string;
}

interface Filters {
    search: string;
    type: string | null;
    part_id: number | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    movements: Paginated<MovementRow>;
    filters: Filters;
    types: string[];
    part: { id: number; code: string; name: string } | null;
    can: { viewTickets: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('stock_movements.title'), href: route('inventory.movements.index') }];

const filters = useListFilters('inventory.movements.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
};

const columns = [
    { key: 'created_at', sortable: true, right: false },
    { key: 'part', sortable: false, right: false },
    { key: 'type', sortable: false, right: false },
    { key: 'quantity', sortable: true, right: true },
    { key: 'balance_after', sortable: false, right: true },
    { key: 'reference', sortable: false, right: false },
    { key: 'user', sortable: false, right: false },
];

const signed = (quantity: number) => (quantity > 0 ? '+' : '') + quantity.toLocaleString('th-TH');

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('stock_movements.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('stock_movements.title')" :description="t('stock_movements.description')" />

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('stock_movements.search_placeholder')" />
                <select v-model="filters.type" :class="selectClass" :aria-label="t('stock_movements.type')">
                    <option :value="null">{{ t('stock_movements.all_types') }}</option>
                    <option v-for="type in types" :key="type" :value="type">{{ t(`stock_movements.types.${type}`) }}</option>
                </select>
                <p v-if="part" class="text-sm text-muted-foreground">
                    {{ t('stock_movements.of_part', { part: `${part.code} ${part.name}` }) }}
                    <button type="button" class="ml-1 text-primary underline-offset-4 hover:underline" @click="filters.part_id = null">
                        {{ t('stock_movements.clear_part') }}
                    </button>
                </p>
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
                                    {{ t(`stock_movements.${column.key}`) }}
                                    <template v-if="filters.sort === column.key">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                                <span v-else>{{ t(`stock_movements.${column.key}`) }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="movement in movements.data" :key="movement.id" class="border-t align-top">
                            <td class="whitespace-nowrap px-4 py-2">{{ dateTime(movement.at) }}</td>
                            <td class="px-4 py-2">
                                <template v-if="movement.part">
                                    <Link
                                        v-if="!movement.part.deleted"
                                        :href="route('inventory.parts.show', movement.part.id)"
                                        class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                    >
                                        {{ movement.part.code }}
                                    </Link>
                                    <span v-else class="font-mono text-xs text-muted-foreground line-through">{{ movement.part.code }}</span>
                                    <div>{{ movement.part.name }}</div>
                                </template>
                                <template v-else>{{ t('common.none') }}</template>
                            </td>
                            <td class="px-4 py-2"><StockMovementTypeBadge :type="movement.type" /></td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-medium">
                                {{ signed(movement.quantity) }}
                                <span class="font-normal text-muted-foreground">{{ movement.part?.unit }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">{{ movement.balance_after.toLocaleString('th-TH') }}</td>
                            <td class="px-4 py-2">
                                <template v-if="movement.ticket">
                                    <Link
                                        v-if="can.viewTickets"
                                        :href="route('service.tickets.show', movement.ticket.ulid)"
                                        class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                    >
                                        {{ movement.ticket.ticket_no }}
                                    </Link>
                                    <span v-else class="font-mono text-xs">{{ movement.ticket.ticket_no }}</span>
                                </template>
                                <span v-if="movement.reference">{{ movement.reference }}</span>
                                <div v-if="movement.note" class="text-xs text-muted-foreground">{{ movement.note }}</div>
                            </td>
                            <td class="px-4 py-2">{{ movement.user_name ?? t('common.system') }}</td>
                        </tr>
                        <tr v-if="movements.data.length === 0">
                            <td :colspan="columns.length" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="movements" />
        </div>
    </AppLayout>
</template>
