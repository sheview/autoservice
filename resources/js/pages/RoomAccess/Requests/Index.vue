<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import RoomRequestStatusBadge from '@/components/RoomRequestStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';

interface Row {
    ulid: string;
    request_no: string;
    status: string;
    requester_name: string | null;
    purpose: string;
    people_count: number;
    room: string | null;
    customer: string;
    planned_start: string;
    planned_end: string;
}

interface Filters {
    search: string;
    status: string;
    room: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    requests: Paginated<Row>;
    filters: Filters;
    statuses: string[];
    rooms: { ulid: string; name: string; customer: string }[];
    // How many wait for this user's decision; null = not an approver.
    awaiting: number | null;
    can: { create: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('room_requests.title'), href: route('room-access.requests.index') }];
const filters = useListFilters('room-access.requests.index', { ...props.filters });
const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('room_requests.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('room_requests.title')" :description="t('room_requests.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('room-access.requests.create')">{{ t('room_requests.create') }}</Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('room_requests.search')" />
                <select v-model="filters.status" :class="selectClass">
                    <option value="open">{{ t('room_requests.open') }}</option>
                    <option v-if="awaiting !== null" value="awaiting">{{ t('room_requests.awaiting') }} ({{ awaiting }})</option>
                    <option value="all">{{ t('room_requests.all_statuses') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`room_requests.statuses.${status}`) }}</option>
                </select>
                <select v-model="filters.room" :class="selectClass">
                    <option :value="null">{{ t('room_requests.all_rooms') }}</option>
                    <option v-for="room in rooms" :key="room.ulid" :value="room.ulid">{{ room.customer }} · {{ room.name }}</option>
                </select>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="inline-flex items-center gap-1" @click="sortBy('request_no')">
                                    {{ t('room_requests.request_no') }}
                                    <component :is="filters.direction === 'asc' ? ArrowUp : ArrowDown" v-if="filters.sort === 'request_no'" class="size-3" />
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('room_requests.room') }}</th>
                            <th class="px-4 py-2 font-medium">
                                <button type="button" class="inline-flex items-center gap-1" @click="sortBy('planned_start')">
                                    {{ t('room_requests.when') }}
                                    <component :is="filters.direction === 'asc' ? ArrowUp : ArrowDown" v-if="filters.sort === 'planned_start'" class="size-3" />
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('room_requests.requester') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('common.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in requests.data" :key="row.ulid" class="border-t align-top">
                            <td class="px-4 py-2">
                                <Link :href="route('room-access.requests.show', row.ulid)" class="font-mono text-primary hover:underline">{{ row.request_no }}</Link>
                                <div class="line-clamp-1 text-xs text-muted-foreground">{{ row.purpose }}</div>
                            </td>
                            <td class="px-4 py-2">{{ row.room }}<div class="text-xs text-muted-foreground">{{ row.customer }}</div></td>
                            <td class="whitespace-nowrap px-4 py-2">
                                {{ dateTime(row.planned_start) }}<div class="text-xs text-muted-foreground">– {{ dateTime(row.planned_end) }}</div>
                            </td>
                            <td class="px-4 py-2">{{ row.requester_name }}<div class="text-xs text-muted-foreground">{{ t('room_requests.people') }} {{ row.people_count }}</div></td>
                            <td class="px-4 py-2"><RoomRequestStatusBadge :status="row.status" /></td>
                        </tr>
                        <tr v-if="requests.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('room_requests.none') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :paginator="requests" />
        </div>
    </AppLayout>
</template>
