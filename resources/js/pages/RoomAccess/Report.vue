<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
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
    entered_at: string;
    exited_at: string | null;
    minutes: number | null;
    entered_by_name: string | null;
    exited_by_name: string | null;
    ulid: string;
    request_no: string;
    room: string | null;
    customer: string;
    requester_name: string | null;
    people: string[];
    purpose: string;
    work_summary: string | null;
    ticket: string | null;
    contract: string | null;
}

interface Filters {
    from: string;
    to: string;
    search: string;
    room: string | null;
    customer_id: number | null;
    contract_id: number | null;
    direction: string;
    [key: string]: unknown;
}

const props = defineProps<{
    rows: Paginated<Row>;
    filters: Filters;
    rooms: { ulid: string; name: string; customer: string }[];
    customers: { id: number; name: string }[];
    contracts: { id: number; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('room_requests.title'), href: route('room-access.requests.index') },
    { title: t('room_report.title'), href: route('room-access.report') },
];
const filters = useListFilters('room-access.report', { ...props.filters });
// The files are made from the same filters as the page.
const query = computed(() => ({
    from: filters.from,
    to: filters.to,
    search: filters.search || undefined,
    room: filters.room ?? undefined,
    customer_id: filters.customer_id ?? undefined,
    contract_id: filters.contract_id ?? undefined,
    direction: filters.direction,
}));

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('room_report.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('room_report.title')" :description="t('room_report.description')" />
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <a :href="route('room-access.report.export', query)">{{ t('room_report.export') }}</a>
                    </Button>
                    <Button variant="outline" as-child>
                        <a :href="route('room-access.report.pdf', query)">{{ t('room_report.pdf') }}</a>
                    </Button>
                    <Button variant="outline" as-child>
                        <a :href="route('room-access.report.print', query)" target="_blank">{{ t('room_report.print') }}</a>
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.from" type="date" class="w-auto" />
                <span class="text-sm text-muted-foreground">–</span>
                <Input v-model="filters.to" type="date" class="w-auto" />
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('room_report.search')" />
                <select v-model="filters.room" :class="selectClass">
                    <option :value="null">{{ t('room_report.all_rooms') }}</option>
                    <option v-for="room in rooms" :key="room.ulid" :value="room.ulid">{{ room.customer }} · {{ room.name }}</option>
                </select>
                <select v-model="filters.customer_id" :class="selectClass">
                    <option :value="null">{{ t('room_report.all_customers') }}</option>
                    <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-if="contracts.length" v-model="filters.contract_id" :class="selectClass">
                    <option :value="null">{{ t('room_report.all_contracts') }}</option>
                    <option v-for="c in contracts" :key="c.id" :value="c.id">{{ c.label }}</option>
                </select>
                <select v-model="filters.direction" :class="selectClass">
                    <option value="desc">{{ t('room_report.newest') }}</option>
                    <option value="asc">{{ t('room_report.oldest') }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('room_report.entered_at') }} / {{ t('room_report.exited_at') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('room_report.room') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('room_report.people') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('room_report.purpose') }} / {{ t('room_report.work_summary') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('room_report.ticket') }} / {{ t('room_report.contract') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows.data" :key="row.id" class="border-t align-top">
                            <td class="px-4 py-2 whitespace-nowrap">
                                <div>{{ dateTime(row.entered_at) }}</div>
                                <div :class="row.exited_at ? '' : 'font-medium text-red-600'">
                                    {{ row.exited_at ? dateTime(row.exited_at) : t('room_report.still_inside') }}
                                </div>
                                <div v-if="row.minutes !== null" class="text-xs text-muted-foreground">{{ t('room_report.duration', { minutes: row.minutes }) }}</div>
                                <Link :href="route('room-access.requests.show', row.ulid)" class="font-mono text-xs hover:underline">{{ row.request_no }}</Link>
                            </td>
                            <td class="px-4 py-2">
                                {{ row.room }}
                                <div class="text-xs text-muted-foreground">{{ row.customer }}</div>
                            </td>
                            <td class="px-4 py-2">
                                {{ row.people.join(', ') }}
                                <div class="text-xs text-muted-foreground">{{ t('room_report.requester') }}: {{ row.requester_name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ t('room_report.entered_by') }}: {{ row.entered_by_name ?? '-' }}
                                    <template v-if="row.exited_by_name"> · {{ t('room_report.exited_by') }}: {{ row.exited_by_name }}</template>
                                </div>
                            </td>
                            <td class="max-w-md px-4 py-2">
                                {{ row.purpose }}
                                <div v-if="row.work_summary" class="text-xs whitespace-pre-line text-muted-foreground">{{ row.work_summary }}</div>
                            </td>
                            <td class="px-4 py-2">
                                <div class="font-mono text-xs">{{ row.ticket ?? '' }}</div>
                                <div class="text-xs text-muted-foreground">{{ row.contract ?? '' }}</div>
                            </td>
                        </tr>
                        <tr v-if="!rows.data.length">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('room_report.none') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="rows" />
        </div>
    </AppLayout>
</template>
