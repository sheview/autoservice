<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import SlaBadge from '@/components/SlaBadge.vue';
import TicketPriorityBadge from '@/components/TicketPriorityBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface TicketRow {
    ulid: string;
    ticket_no: string;
    title: string;
    status: string;
    priority: string;
    customer: string | null;
    assignee: string | null;
    out_of_contract: boolean;
    resolve_due_at: string | null;
    sla: { response: string; resolve: string };
    created_at: string;
}

interface Filters {
    search: string;
    status: string;
    priority: string | null;
    assignee: string | null;
    customer_id: number | null;
    sla: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    tickets: Paginated<TicketRow>;
    filters: Filters;
    statuses: string[];
    priorities: string[];
    customers: { id: number; code: string; name: string }[];
    dueSoonHours: number;
    can: { create: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('tickets.title'), href: route('service.tickets.index') }];

const filters = useListFilters('service.tickets.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'desc' ? 'asc' : 'desc';
    filters.sort = column;
};

const columns = [
    { key: 'ticket_no', label: 'tickets.ticket_no', sortable: true },
    { key: 'title', label: 'tickets.title_field', sortable: false },
    { key: 'priority', label: 'tickets.priority', sortable: true },
    { key: 'status', label: 'tickets.status', sortable: false },
    { key: 'assignee', label: 'tickets.assignee', sortable: false },
    { key: 'resolve_due_at', label: 'tickets.sla', sortable: true },
    { key: 'created_at', label: 'tickets.created_at', sortable: true },
];

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('tickets.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('tickets.title')" :description="t('tickets.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('service.tickets.create')">{{ t('tickets.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('tickets.search_placeholder')" />
                <select v-model="filters.status" :class="selectClass" :aria-label="t('tickets.status')">
                    <option value="open">{{ t('tickets.open_statuses') }}</option>
                    <option value="all">{{ t('tickets.all_statuses') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`tickets.statuses.${status}`) }}</option>
                </select>
                <select v-model="filters.priority" :class="selectClass" :aria-label="t('tickets.priority')">
                    <option :value="null">{{ t('tickets.all_priorities') }}</option>
                    <option v-for="priority in priorities" :key="priority" :value="priority">{{ t(`tickets.priorities.${priority}`) }}</option>
                </select>
                <select v-model="filters.assignee" :class="selectClass" :aria-label="t('tickets.assignee')">
                    <option :value="null">{{ t('tickets.all_assignees') }}</option>
                    <option value="me">{{ t('tickets.assigned_to_me') }}</option>
                    <option value="none">{{ t('tickets.unassigned') }}</option>
                </select>
                <select v-model="filters.sla" :class="selectClass" :aria-label="t('tickets.sla')">
                    <option :value="null">{{ t('tickets.all_sla') }}</option>
                    <option value="breached">{{ t('tickets.sla_filters.breached') }}</option>
                    <option value="due_soon">{{ t('tickets.sla_filters.due_soon', { hours: dueSoonHours }) }}</option>
                </select>
                <select v-if="customers.length" v-model="filters.customer_id" :class="selectClass" :aria-label="t('tickets.customer')">
                    <option :value="null">{{ t('tickets.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
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
                        <tr v-for="ticket in tickets.data" :key="ticket.ulid" class="border-t align-top">
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                <Link :href="route('service.tickets.show', ticket.ulid)" class="text-primary underline-offset-4 hover:underline">
                                    {{ ticket.ticket_no }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ ticket.title }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ ticket.customer ?? t('tickets.no_customer') }}
                                    <span v-if="ticket.out_of_contract" class="ml-1 rounded bg-muted px-1">{{ t('tickets.out_of_contract') }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2"><TicketPriorityBadge :priority="ticket.priority" /></td>
                            <td class="px-4 py-2"><TicketStatusBadge :status="ticket.status" /></td>
                            <td class="px-4 py-2">{{ ticket.assignee ?? t('tickets.unassigned') }}</td>
                            <td class="px-4 py-2">
                                <div class="flex flex-col">
                                    <SlaBadge :state="ticket.sla.response" :label="t('tickets.sla_response')" />
                                    <SlaBadge :state="ticket.sla.resolve" :label="t('tickets.sla_resolve')" />
                                    <span v-if="ticket.resolve_due_at && ticket.sla.resolve === 'pending'" class="text-xs text-muted-foreground">
                                        {{ t('tickets.due', { at: dateTime(ticket.resolve_due_at) }) }}
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">{{ dateTime(ticket.created_at) }}</td>
                        </tr>
                        <tr v-if="tickets.data.length === 0">
                            <td :colspan="columns.length" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="tickets" />
        </div>
    </AppLayout>
</template>
