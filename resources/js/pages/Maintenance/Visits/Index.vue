<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import PmVisitViewSwitch from '@/components/PmVisitViewSwitch.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface VisitRow {
    ulid: string;
    visit_no: string;
    round: number;
    status: string;
    items_count: number;
    checked_count: number;
    issue_count: number;
    plan: string | null;
    customer: string | null;
    assignee: string | null;
    due_on: string;
    scheduled_on: string | null;
    overdue: boolean;
    /** Moves this person may make on it from the list, together with other work orders. */
    bulk: string[];
}

interface Filters {
    search: string;
    status: string;
    customer_id: number | null;
    assignee: string | null;
    month: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    visits: Paginated<VisitRow>;
    filters: Filters;
    statuses: string[];
    customers: { id: number; code: string; name: string }[];
    assignees: { id: number; name: string }[];
    can: { create: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_visits.title'), href: route('maintenance.visits.index') }];

const filters = useListFilters('maintenance.visits.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const columns = [
    { key: 'visit_no', label: 'pm_visits.visit_no', sortable: true },
    { key: 'plan', label: 'pm_visits.plan', sortable: false },
    { key: 'due_on', label: 'pm_visits.due_on', sortable: true },
    { key: 'scheduled_on', label: 'pm_visits.scheduled_on', sortable: true },
    { key: 'assignee', label: 'pm_visits.assignee', sortable: false },
    { key: 'progress', label: 'pm_visits.progress', sortable: false },
    { key: 'status', label: 'pm_visits.status', sortable: false },
];

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

// Work orders ticked on this page, to review / approve / publish / close together (the end of a round).
const bulkActions = ['review', 'approve', 'publish', 'close'];
const selectable = computed(() => props.visits.data.filter((visit) => visit.bulk.length > 0));
const selected = ref<string[]>([]);
const allSelected = computed(() => selectable.value.length > 0 && selected.value.length === selectable.value.length);
const toggleAll = (on: boolean) => (selected.value = on ? selectable.value.map((visit) => visit.ulid) : []);
// A move is offered when at least one ticked work order allows it; the others are skipped and listed.
const offered = computed(() =>
    bulkActions.filter((action) => props.visits.data.some((visit) => selected.value.includes(visit.ulid) && visit.bulk.includes(action))),
);
const moving = ref(false);
const bulkMove = (action: string) =>
    router.post(
        route('maintenance.visits.bulk-move'),
        { action, visits: selected.value },
        {
            preserveScroll: true,
            onStart: () => (moving.value = true),
            onSuccess: () => (selected.value = []),
            onFinish: () => (moving.value = false),
        },
    );
// Another page or filter: nothing ticked carries over.
watch(
    () => props.visits,
    () => (selected.value = selected.value.filter((ulid) => selectable.value.some((visit) => visit.ulid === ulid))),
);
const errors = computed(() => Object.values(page.props.errors as Record<string, string>));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_visits.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('pm_visits.title')" :description="t('pm_visits.description')" />
                <div class="flex flex-wrap items-center gap-2">
                    <PmVisitViewSwitch current="list" />
                    <Button v-if="can.create" as-child>
                        <Link :href="route('maintenance.visits.create')">{{ t('pm_visits.create') }}</Link>
                    </Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError v-for="(message, index) in errors" :key="index" :message="message" />

            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('pm_visits.search_placeholder')" />
                <select v-model="filters.status" :class="selectClass" :aria-label="t('pm_visits.status')">
                    <option value="open">{{ t('pm_visits.open_statuses') }}</option>
                    <option value="overdue">{{ t('pm_visits.overdue_filter') }}</option>
                    <option v-for="status in statuses" :key="status" :value="status">{{ t(`pm_visits.statuses.${status}`) }}</option>
                    <option value="all">{{ t('pm_visits.all_statuses') }}</option>
                </select>
                <select v-if="customers.length" v-model="filters.customer_id" :class="selectClass" :aria-label="t('pm_visits.customer')">
                    <option :value="null">{{ t('pm_visits.all_customers') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <select v-if="assignees.length" v-model="filters.assignee" :class="selectClass" :aria-label="t('pm_visits.assignee')">
                    <option :value="null">{{ t('pm_visits.all_assignees') }}</option>
                    <option value="me">{{ t('pm_visits.mine') }}</option>
                    <option value="none">{{ t('pm_visits.unassigned') }}</option>
                    <option v-for="user in assignees" :key="user.id" :value="String(user.id)">{{ user.name }}</option>
                </select>
                <Input v-model="filters.month as string" type="month" class="w-44" :aria-label="t('pm_visits.month')" :title="t('pm_visits.month')" />
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="w-10 px-4 py-2">
                                <input
                                    v-if="selectable.length"
                                    type="checkbox"
                                    class="size-4 rounded border-input"
                                    :checked="allSelected"
                                    :indeterminate="selected.length > 0 && !allSelected"
                                    :aria-label="t('pm_visits.select_all')"
                                    :title="t('pm_visits.select_all')"
                                    @change="toggleAll(($event.target as HTMLInputElement).checked)"
                                />
                            </th>
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
                        <tr v-for="visit in visits.data" :key="visit.ulid" class="border-t">
                            <td class="px-4 py-2">
                                <input
                                    v-if="visit.bulk.length"
                                    v-model="selected"
                                    type="checkbox"
                                    class="size-4 rounded border-input"
                                    :value="visit.ulid"
                                    :aria-label="visit.visit_no"
                                />
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <Link
                                    :href="route('maintenance.visits.show', visit.ulid)"
                                    class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                >
                                    {{ visit.visit_no }}
                                </Link>
                                <div class="text-xs text-muted-foreground">{{ t('pm_visits.round', { round: visit.round }) }}</div>
                            </td>
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ visit.plan ?? t('common.none') }}</div>
                                <div class="text-xs text-muted-foreground">{{ visit.customer ?? t('common.none') }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">{{ visit.due_on }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ visit.scheduled_on ?? t('pm_visits.not_scheduled') }}</td>
                            <td class="px-4 py-2">{{ visit.assignee ?? t('pm_visits.unassigned') }}</td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <template v-if="visit.items_count">
                                    {{ t('pm_visits.progress_value', { done: visit.checked_count, total: visit.items_count }) }}
                                    <div v-if="visit.issue_count" class="text-xs text-red-600 dark:text-red-400">
                                        {{ t('pm_visits.issues', { count: visit.issue_count }) }}
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground">{{ t('common.none') }}</span>
                            </td>
                            <td class="px-4 py-2"><PmVisitStatusBadge :status="visit.status" :overdue="visit.overdue" /></td>
                        </tr>
                        <tr v-if="visits.data.length === 0">
                            <td :colspan="columns.length + 1" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- What to do with the ticked work orders -->
            <div
                v-if="selected.length"
                class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-background px-4 py-3 shadow-lg"
            >
                <span class="text-sm font-medium">{{ t('pm_visits.selected', { count: selected.length }) }}</span>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" :disabled="moving" @click="selected = []">{{ t('common.cancel') }}</Button>
                    <Button v-for="action in offered" :key="action" size="sm" :disabled="moving" @click="bulkMove(action)">
                        {{ t(`pm_visits.moves.${action}`) }}
                    </Button>
                </div>
            </div>

            <Pagination :paginator="visits" />
        </div>
    </AppLayout>
</template>
