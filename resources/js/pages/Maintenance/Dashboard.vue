<script setup lang="ts">
import ColumnChart from '@/components/ColumnChart.vue';
import Heading from '@/components/Heading.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface VisitRow {
    ulid: string;
    visit_no: string;
    status: string;
    title: string | null;
    customer: string | null;
    due_on: string;
    overdue: boolean;
}

const props = defineProps<{
    counts: Record<string, number>;
    thisMonth: { due: number; done: number; on_time: number; overdue: number; compliance: number | null };
    trend: { month: string; due: number; done: number }[];
    // rounds handed in of the rounds due this year up to today, per plan (lowest first)
    plans: { plan_id: number; plan: string | null; due: number; done: number; progress: number }[];
    waiting: { review: VisitRow[]; approve: VisitRow[]; publish: VisitRow[]; overdue: VisitRow[] };
    history: {
        id: number;
        type: string;
        note: string | null;
        user_name: string | null;
        visit: { ulid: string; visit_no: string } | null;
        at: string;
    }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_dashboard.title'), href: route('maintenance.dashboard') }];

// Where the work stands now; the overdue tile stands out when there is any.
const tiles = computed(() => [
    { key: 'overdue', value: props.counts.overdue, query: { status: 'overdue' }, alert: props.counts.overdue > 0 },
    { key: 'due_this_month', value: props.counts.due_this_month, query: {} },
    { key: 'working', value: props.counts.in_progress + props.counts.revision_required, query: { status: 'in_progress' } },
    { key: 'to_review', value: props.counts.submitted, query: { status: 'submitted' } },
    { key: 'to_approve', value: props.counts.pending_approval, query: { status: 'pending_approval' } },
    { key: 'to_publish', value: props.counts.approved, query: { status: 'approved' } },
]);

const monthLabel = (month: string) => new Date(`${month}-01T00:00:00`).toLocaleDateString('th-TH-u-ca-gregory', { month: 'short', year: '2-digit' });
const chart = computed(() => ({
    title: t('pm_dashboard.trend'),
    categories: props.trend.map((row) => monthLabel(row.month)),
    series: [
        { key: 'due', label: t('pm_dashboard.due'), values: props.trend.map((row) => row.due) },
        { key: 'done', label: t('pm_dashboard.done'), values: props.trend.map((row) => row.done) },
    ],
}));

const lists = computed(() =>
    (['overdue', 'review', 'approve', 'publish'] as const).map((key) => ({ key, rows: props.waiting[key] })).filter((list) => list.rows.length),
);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_dashboard.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('pm_dashboard.title')" :description="t('pm_dashboard.description')" />
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child
                        ><Link :href="route('maintenance.reports.index')">{{ t('nav.pm_reports') }}</Link></Button
                    >
                    <Button as-child
                        ><Link :href="route('maintenance.visits.index')">{{ t('nav.pm_visits') }}</Link></Button
                    >
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <Link
                    v-for="tile in tiles"
                    :key="tile.key"
                    :href="route('maintenance.visits.index', tile.query)"
                    class="rounded-lg border p-3 transition hover:bg-muted/50"
                    :class="{ 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40': tile.alert }"
                >
                    <div class="text-sm text-muted-foreground">{{ t(`pm_dashboard.tiles.${tile.key}`) }}</div>
                    <div class="text-2xl font-semibold tabular-nums" :class="{ 'text-red-700 dark:text-red-400': tile.alert }">{{ tile.value }}</div>
                </Link>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="space-y-2 rounded-md border p-4">
                    <h3 class="text-sm font-semibold">{{ t('pm_dashboard.this_month') }}</h3>
                    <p class="text-4xl font-semibold tabular-nums">{{ thisMonth.compliance === null ? '–' : `${thisMonth.compliance}%` }}</p>
                    <p class="text-sm text-muted-foreground">{{ t('pm_dashboard.compliance') }}</p>
                    <dl class="grid grid-cols-3 gap-2 border-t pt-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('pm_dashboard.due') }}</dt>
                            <dd class="tabular-nums">{{ thisMonth.due }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('pm_dashboard.done') }}</dt>
                            <dd class="tabular-nums">{{ thisMonth.done }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('pm_dashboard.on_time') }}</dt>
                            <dd class="tabular-nums">{{ thisMonth.on_time }}</dd>
                        </div>
                    </dl>
                </div>
                <div class="lg:col-span-2">
                    <ColumnChart v-bind="chart" />
                </div>
            </div>

            <!-- Progress of each plan this year -->
            <section v-if="plans.length" class="space-y-2 rounded-md border p-4">
                <h3 class="text-sm font-semibold">{{ t('pm_dashboard.plans') }}</h3>
                <ul class="grid gap-x-6 gap-y-2 md:grid-cols-2">
                    <li v-for="row in plans" :key="row.plan_id" class="space-y-1">
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <Link
                                :href="route('maintenance.plans.show', row.plan_id)"
                                class="truncate text-primary underline-offset-4 hover:underline"
                                >{{ row.plan ?? '-' }}</Link
                            >
                            <span class="shrink-0 text-xs tabular-nums text-muted-foreground"
                                >{{ t('pm_dashboard.plan_rounds', { done: row.done, due: row.due }) }} · {{ row.progress }}%</span
                            >
                        </div>
                        <div class="h-2 overflow-hidden rounded bg-muted">
                            <div
                                class="h-full rounded"
                                :class="row.progress < 80 ? 'bg-amber-500' : 'bg-emerald-600'"
                                :style="{ width: `${row.progress}%` }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <!-- What waits for this user -->
            <div v-if="lists.length" class="grid gap-4 lg:grid-cols-2">
                <section v-for="list in lists" :key="list.key" class="space-y-2">
                    <h3 class="text-sm font-semibold" :class="{ 'text-red-700 dark:text-red-400': list.key === 'overdue' }">
                        {{ t(`pm_dashboard.waiting.${list.key}`) }} · {{ list.rows.length }}
                    </h3>
                    <ul class="divide-y rounded-md border text-sm">
                        <li v-for="row in list.rows" :key="row.ulid" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <span class="min-w-0">
                                <Link
                                    :href="route('maintenance.visits.show', row.ulid)"
                                    class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                    >{{ row.visit_no }}</Link
                                >
                                <span class="ml-2">{{ row.title }}</span>
                                <span class="block text-xs text-muted-foreground"
                                    >{{ row.customer }} · {{ t('pm_visits.due_on') }} {{ row.due_on }}</span
                                >
                            </span>
                            <PmVisitStatusBadge :status="row.status" :overdue="row.overdue" />
                        </li>
                    </ul>
                </section>
            </div>
            <p v-else class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                {{ t('pm_dashboard.nothing_waiting') }}
            </p>

            <section v-if="history.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('pm_dashboard.history') }}</h3>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="event in history" :key="event.id" class="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                        <span>
                            <Link
                                v-if="event.visit"
                                :href="route('maintenance.visits.show', event.visit.ulid)"
                                class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                >{{ event.visit.visit_no }}</Link
                            >
                            <span class="ml-2">{{ t(`pm_visits.events.${event.type}`) }}</span>
                            <span v-if="event.note" class="ml-1 text-muted-foreground">— {{ event.note }}</span>
                        </span>
                        <span class="text-xs text-muted-foreground"
                            >{{ event.user_name ?? t('pm_dashboard.customer_or_system') }} · {{ dateTime(event.at) }}</span
                        >
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
