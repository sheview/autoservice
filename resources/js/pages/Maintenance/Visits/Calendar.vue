<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import PmVisitViewSwitch from '@/components/PmVisitViewSwitch.vue';
import { Button } from '@/components/ui/button';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

interface CalendarVisit {
    ulid: string;
    visit_no: string;
    round: number;
    status: string;
    calendar_date: string;
    plan: string | null;
    customer: string | null;
    assignee: string | null;
    scheduled: boolean;
    overdue: boolean;
}

interface Filters {
    month: string;
    customer_id: number | null;
    assignee: string | null;
    [key: string]: unknown;
}

const props = defineProps<{
    visits: CalendarVisit[];
    filters: Filters;
    customers: { id: number; code: string; name: string }[];
    assignees: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('pm_visits.title'), href: route('maintenance.visits.index') },
    { title: t('pm_visits.calendar.title'), href: route('maintenance.calendar') },
];

const filters = useListFilters('maintenance.calendar', props.filters);

const MAX_PER_DAY = 3;

const pad = (n: number) => String(n).padStart(2, '0');
const ymd = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
// Today in Bangkok, as Y-m-d.
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Bangkok' });

const year = computed(() => Number(filters.month.slice(0, 4)));
const month = computed(() => Number(filters.month.slice(5, 7))); // 1-12
const title = computed(() => `${t(`pm_visits.calendar.months.${month.value - 1}`)} ${year.value}`);

const shift = (months: number) => {
    const d = new Date(year.value, month.value - 1 + months, 1);
    filters.month = `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
};
const thisMonth = () => (filters.month = today.slice(0, 7));

// Weeks start on Monday; days of the neighbouring months fill the first and last week.
const weeks = computed(() => {
    const first = new Date(year.value, month.value - 1, 1);
    const start = new Date(first);
    start.setDate(1 - ((first.getDay() + 6) % 7));

    const byDay = new Map<string, CalendarVisit[]>();
    for (const visit of props.visits) {
        byDay.set(visit.calendar_date, [...(byDay.get(visit.calendar_date) ?? []), visit]);
    }

    const rows: { date: string; day: number; inMonth: boolean; visits: CalendarVisit[] }[][] = [];
    const cursor = new Date(start);
    do {
        const row = [];
        for (let i = 0; i < 7; i++) {
            const date = ymd(cursor);
            row.push({ date, day: cursor.getDate(), inMonth: cursor.getMonth() === month.value - 1, visits: byDay.get(date) ?? [] });
            cursor.setDate(cursor.getDate() + 1);
        }
        rows.push(row);
    } while (cursor.getMonth() === month.value - 1);

    return rows;
});

const chipClass = (visit: CalendarVisit) => {
    if (visit.overdue) return 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200';
    return (
        {
            scheduled: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200',
            in_progress: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
            completed: 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200',
        }[visit.status] ?? ''
    );
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_visits.calendar.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('pm_visits.calendar.title')" :description="t('pm_visits.calendar.description')" />
                <PmVisitViewSwitch current="calendar" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-1">
                    <Button variant="outline" size="icon" :aria-label="t('pm_visits.calendar.previous')" @click="shift(-1)">
                        <ChevronLeft class="h-4 w-4" />
                    </Button>
                    <Button variant="outline" size="icon" :aria-label="t('pm_visits.calendar.next')" @click="shift(1)">
                        <ChevronRight class="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" size="sm" @click="thisMonth">{{ t('pm_visits.calendar.today') }}</Button>
                </div>
                <h2 class="min-w-40 text-lg font-semibold">{{ title }}</h2>
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
            </div>

            <div class="overflow-x-auto">
                <div class="min-w-[720px] overflow-hidden rounded-md border">
                    <div class="grid grid-cols-7 bg-muted/50 text-center text-xs font-medium">
                        <div v-for="i in 7" :key="i" class="px-2 py-2">{{ t(`pm_visits.calendar.weekdays.${i - 1}`) }}</div>
                    </div>
                    <div v-for="(week, w) in weeks" :key="w" class="grid grid-cols-7 border-t">
                        <div
                            v-for="cell in week"
                            :key="cell.date"
                            class="min-h-28 space-y-1 border-l p-1.5 first:border-l-0"
                            :class="{ 'bg-muted/30 text-muted-foreground': !cell.inMonth }"
                        >
                            <div
                                class="flex h-6 w-6 items-center justify-center rounded-full text-xs"
                                :class="{ 'bg-primary font-semibold text-primary-foreground': cell.date === today }"
                            >
                                {{ cell.day }}
                            </div>
                            <Link
                                v-for="visit in cell.visits.slice(0, MAX_PER_DAY)"
                                :key="visit.ulid"
                                :href="route('maintenance.visits.show', visit.ulid)"
                                class="block truncate rounded border px-1.5 py-0.5 text-xs hover:opacity-80"
                                :class="[chipClass(visit), { 'border-dashed': !visit.scheduled }]"
                                :title="`${visit.visit_no} · ${visit.plan ?? ''} · ${visit.customer ?? ''} · ${visit.assignee ?? t('pm_visits.unassigned')}`"
                            >
                                {{ visit.customer ?? visit.visit_no }}
                            </Link>
                            <Link
                                v-if="cell.visits.length > MAX_PER_DAY"
                                :href="route('maintenance.visits.index', { status: 'all', month: filters.month })"
                                class="block px-1.5 text-xs text-muted-foreground hover:underline"
                            >
                                {{ t('pm_visits.calendar.more', { count: cell.visits.length - MAX_PER_DAY }) }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                <span class="flex items-center gap-1"
                    ><span class="h-3 w-3 rounded border border-blue-200 bg-blue-50" />{{ t('pm_visits.statuses.scheduled') }}</span
                >
                <span class="flex items-center gap-1"
                    ><span class="h-3 w-3 rounded border border-amber-200 bg-amber-50" />{{ t('pm_visits.statuses.in_progress') }}</span
                >
                <span class="flex items-center gap-1"
                    ><span class="h-3 w-3 rounded border border-green-200 bg-green-50" />{{ t('pm_visits.statuses.completed') }}</span
                >
                <span class="flex items-center gap-1"
                    ><span class="h-3 w-3 rounded border border-red-300 bg-red-50" />{{ t('pm_visits.overdue') }}</span
                >
                <span class="flex items-center gap-1"
                    ><span class="h-3 w-3 rounded border border-dashed border-foreground/40" />{{ t('pm_visits.calendar.due_marker') }}</span
                >
                <span v-if="visits.length === 0">· {{ t('pm_visits.calendar.empty') }}</span>
            </div>
        </div>
    </AppLayout>
</template>
