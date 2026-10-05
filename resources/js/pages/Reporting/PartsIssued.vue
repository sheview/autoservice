<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import StockMovementTypeBadge from '@/components/StockMovementTypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Row {
    id: number;
    at: string;
    type: string;
    part: { id: number; code: string; name: string; unit: string; brand: string | null; part_number: string | null } | null;
    quantity: number;
    serials: string[];
    returned: string[];
    ticket: { ulid: string; ticket_no: string } | null;
    reference: string | null;
    customer: string | null;
    contract: string | null;
    asset: string | null;
    user_name: string | null;
    value: string | null;
}

interface Filters {
    from: string;
    to: string;
    search: string;
    contract_id: number | null;
    customer_id: number | null;
    [key: string]: unknown;
}

const props = defineProps<{
    rows: Paginated<Row>;
    filters: Filters;
    contracts: { id: number; label: string }[];
    customers: { id: number; code: string; name: string }[];
    can: { export: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('parts_issued.title'), href: route('reporting.parts-issued.index') }];
const filters = useListFilters('reporting.parts-issued.index', { ...props.filters });
// The files are made from the same filters as the page.
const query = computed(() => ({
    from: filters.from,
    to: filters.to,
    search: filters.search || undefined,
    contract_id: filters.contract_id ?? undefined,
    customer_id: filters.customer_id ?? undefined,
}));

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('parts_issued.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('parts_issued.title')" :description="t('parts_issued.description')" />
                <div v-if="can.export" class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <a :href="route('reporting.parts-issued.export', query)">{{ t('parts_issued.export') }}</a>
                    </Button>
                    <Button variant="outline" as-child>
                        <a :href="route('reporting.parts-issued.pdf', query)">{{ t('parts_issued.pdf') }}</a>
                    </Button>
                    <Button variant="outline" as-child>
                        <a :href="route('reporting.parts-issued.print', query)" target="_blank">{{ t('parts_issued.print') }}</a>
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <label class="grid gap-1 text-xs text-muted-foreground"
                    >{{ t('parts_issued.from') }}<Input v-model="filters.from" type="date" class="w-40"
                /></label>
                <label class="grid gap-1 text-xs text-muted-foreground"
                    >{{ t('parts_issued.to') }}<Input v-model="filters.to" type="date" class="w-40"
                /></label>
                <select v-if="contracts.length" v-model="filters.contract_id" :class="selectClass" :aria-label="t('parts_issued.contract')">
                    <option :value="null">{{ t('parts_issued.all_contracts') }}</option>
                    <option v-for="contract in contracts" :key="contract.id" :value="contract.id">{{ contract.label }}</option>
                </select>
                <select v-if="customers.length" v-model="filters.customer_id" :class="selectClass" :aria-label="t('parts_issued.customer')">
                    <option :value="null">{{ t('parts_issued.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <Input v-model="filters.search" type="search" class="max-w-xs font-mono" :placeholder="t('parts_issued.search')" />
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ t('parts_issued.date') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('parts_issued.part') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ t('parts_issued.quantity') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('parts_issued.serials') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('parts_issued.ticket') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('parts_issued.customer') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('parts_issued.by') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows.data" :key="row.id" class="border-t align-top">
                            <td class="whitespace-nowrap px-3 py-2">
                                {{ dateTime(row.at) }}
                                <div><StockMovementTypeBadge :type="row.type" /></div>
                            </td>
                            <td class="px-3 py-2">
                                <Link v-if="row.part" :href="route('inventory.parts.show', row.part.id)" class="font-mono text-xs text-primary hover:underline">{{
                                    row.part.code
                                }}</Link>
                                <div>{{ row.part?.name }}</div>
                                <div v-if="row.part?.brand || row.part?.part_number" class="text-xs text-muted-foreground">
                                    {{ [row.part?.brand, row.part?.part_number].filter(Boolean).join(' ') }}
                                </div>
                                <div v-if="row.asset" class="text-xs text-muted-foreground">{{ row.asset }}</div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">{{ row.quantity }} {{ row.part?.unit }}</td>
                            <td class="px-3 py-2">
                                <div class="flex max-w-xs flex-wrap gap-1">
                                    <span
                                        v-for="serial in row.serials"
                                        :key="serial"
                                        class="rounded border px-1.5 font-mono text-xs"
                                        :class="{ 'text-muted-foreground line-through': row.returned.includes(serial) }"
                                        >{{ serial }}</span
                                    >
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <Link v-if="row.ticket" :href="route('service.tickets.show', row.ticket.ulid)" class="font-mono text-xs text-primary hover:underline">{{
                                    row.ticket.ticket_no
                                }}</Link>
                                <span v-else class="text-xs">{{ row.reference }}</span>
                            </td>
                            <td class="px-3 py-2">
                                {{ row.customer }}
                                <div v-if="row.contract" class="text-xs text-muted-foreground">{{ row.contract }}</div>
                            </td>
                            <td class="px-3 py-2">{{ row.user_name }}</td>
                        </tr>
                        <tr v-if="rows.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ t('parts_issued.none') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="rows" />
        </div>
    </AppLayout>
</template>
