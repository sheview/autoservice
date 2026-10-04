<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

interface SlaClock {
    met: number;
    breached: number;
    pending: number;
    rate: number | null;
}

interface Report {
    period: { from: string; to: string };
    tickets: {
        opened: number;
        closed: number;
        cancelled: number;
        backlog: number;
        avg_resolve_hours: number | null;
        by_status: Record<string, number>;
        by_priority: Record<string, number>;
        sla: { response: SlaClock; resolve: SlaClock };
        trend: { label: string; count: number }[];
        trend_unit: 'day' | 'month';
    } | null;
    customers: { name: string; tickets: number }[];
    technicians: { name: string; tickets: number; closed: number; resolve_breached: number; answers: number; average: number | null }[];
    pm: {
        due: number;
        completed: number;
        on_time: number;
        in_progress: number;
        overdue: number;
        scheduled: number;
        cancelled: number;
        compliance: number | null;
        items: Record<string, number>;
    } | null;
    assets: {
        total: number;
        by_status: Record<string, number>;
        by_category: { name: string; count: number }[];
        warranty_expiring: number;
        warranty_expired: number;
        expiring_days: number;
    } | null;
    parts: {
        received: number;
        used: number;
        used_on_tickets: number;
        used_value: string;
        low: number;
        out: number;
        top: { code: string; name: string; unit: string; quantity: number; value: string | null }[];
    } | null;
    surveys: { sent: number; answered: number; response_rate: number | null; average: number | null; scores: Record<string, number> } | null;
}

const props = defineProps<{ report: Report; can: { export: boolean } }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('reports.title'), href: route('reporting.reports.index') }];

// --- period ----------------------------------------------------------------------
const period = reactive({ ...props.report.period });
const show = () => router.get(route('reporting.reports.index'), { ...period }, { preserveScroll: true });

const iso = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const daysAgo = (days: number) => {
    const date = new Date();
    date.setDate(date.getDate() - days);
    return date;
};
const presets: Record<string, () => [Date, Date]> = {
    this_month: () => [new Date(new Date().getFullYear(), new Date().getMonth(), 1), new Date()],
    last_month: () => [
        new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1),
        new Date(new Date().getFullYear(), new Date().getMonth(), 0),
    ],
    last_30: () => [daysAgo(29), new Date()],
    last_90: () => [daysAgo(89), new Date()],
    this_year: () => [new Date(new Date().getFullYear(), 0, 1), new Date()],
};
const usePreset = (key: string) => {
    const [from, to] = presets[key]();
    period.from = iso(from);
    period.to = iso(to);
    show();
};

const exportUrl = computed(() => route('reporting.reports.export', props.report.period));

// --- display ---------------------------------------------------------------------
const number = (value: number) => value.toLocaleString('th-TH');
const orNone = (value: number | null, suffix = '') => (value === null ? t('common.none') : `${number(value)}${suffix}`);
const money = (baht: string | null) => (baht === null ? t('common.none') : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));
// Width of a bar as a share of the largest value in its list.
const share = (value: number, max: number) => (max > 0 ? `${Math.max(2, Math.round((value * 100) / max))}%` : '0%');
const maxOf = (values: number[]) => Math.max(0, ...values);

const trendMax = computed(() => maxOf(props.report.tickets?.trend.map((point) => point.count) ?? []));
// Day labels are long: show the day of the month only, and the full date on hover.
const trendLabel = (label: string) => (props.report.tickets?.trend_unit === 'day' ? label.slice(8) : label.slice(2));

const selectable = 'rounded-md border px-3 py-1 text-sm hover:bg-muted';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('reports.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('reports.title')" :description="t('reports.description')" />
                <Button v-if="can.export" variant="outline" as-child>
                    <a :href="exportUrl">{{ t('reports.export') }}</a>
                </Button>
            </div>

            <!-- Period: one row above everything it filters -->
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="show">
                <div class="grid gap-1">
                    <Label for="from">{{ t('reports.from') }}</Label>
                    <Input id="from" v-model="period.from" type="date" required class="w-40" />
                </div>
                <div class="grid gap-1">
                    <Label for="to">{{ t('reports.to') }}</Label>
                    <Input id="to" v-model="period.to" type="date" required class="w-40" />
                </div>
                <Button>{{ t('reports.apply') }}</Button>
                <div class="flex flex-wrap gap-2">
                    <button v-for="(_, key) in presets" :key="key" type="button" :class="selectable" @click="usePreset(key)">
                        {{ t(`reports.presets.${key}`) }}
                    </button>
                </div>
            </form>

            <!-- Tickets -->
            <section v-if="report.tickets" class="space-y-4">
                <h3 class="text-base font-semibold">{{ t('reports.sections.tickets') }}</h3>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.opened') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.tickets.opened) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.closed') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.tickets.closed) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.cancelled') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.tickets.cancelled) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.backlog') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.tickets.backlog) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.avg_resolve_hours') }}</p>
                        <p class="text-2xl font-semibold">{{ orNone(report.tickets.avg_resolve_hours) }}</p>
                    </div>
                </div>

                <div class="rounded-md border p-4">
                    <p class="mb-3 text-sm font-medium">
                        {{ t('reports.trend') }}
                        <span class="font-normal text-muted-foreground">· {{ t(`reports.trend_units.${report.tickets.trend_unit}`) }}</span>
                    </p>
                    <div v-if="trendMax > 0" class="flex h-32 items-end gap-0.5 overflow-x-auto">
                        <div
                            v-for="point in report.tickets.trend"
                            :key="point.label"
                            class="flex h-full min-w-3 flex-1 flex-col justify-end"
                            :title="`${point.label}: ${point.count}`"
                        >
                            <div class="rounded-t bg-primary" :style="{ height: point.count ? share(point.count, trendMax) : '0' }" />
                            <div class="mt-1 text-center text-[11px] leading-none text-muted-foreground">{{ trendLabel(point.label) }}</div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">{{ t('reports.no_data') }}</p>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-md border p-4">
                        <p class="mb-3 text-sm font-medium">{{ t('reports.by_status') }}</p>
                        <div v-for="(count, status) in report.tickets.by_status" :key="status" class="mb-1.5 flex items-center gap-2 text-sm">
                            <span class="w-32 shrink-0">{{ t(`tickets.statuses.${status}`) }}</span>
                            <span class="h-2 flex-1 rounded bg-muted">
                                <span
                                    class="block h-full rounded bg-primary"
                                    :style="{ width: count ? share(count, maxOf(Object.values(report.tickets.by_status))) : '0' }"
                                />
                            </span>
                            <span class="w-10 text-right tabular-nums">{{ number(count) }}</span>
                        </div>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="mb-3 text-sm font-medium">{{ t('reports.by_priority') }}</p>
                        <div v-for="(count, priority) in report.tickets.by_priority" :key="priority" class="mb-1.5 flex items-center gap-2 text-sm">
                            <span class="w-32 shrink-0">{{ t(`tickets.priorities.${priority}`) }}</span>
                            <span class="h-2 flex-1 rounded bg-muted">
                                <span
                                    class="block h-full rounded bg-primary"
                                    :style="{ width: count ? share(count, maxOf(Object.values(report.tickets.by_priority))) : '0' }"
                                />
                            </span>
                            <span class="w-10 text-right tabular-nums">{{ number(count) }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div v-for="clock in ['response', 'resolve'] as const" :key="clock" class="rounded-md border p-4">
                        <p class="text-sm font-medium">{{ t(`reports.sections.sla_${clock}`) }}</p>
                        <p class="text-2xl font-semibold">
                            {{ orNone(report.tickets.sla[clock].rate, '%') }}
                            <span class="text-sm font-normal text-muted-foreground">{{ t('reports.sla_rate') }}</span>
                        </p>
                        <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ t('reports.sla_met') }}</dt>
                                <dd class="tabular-nums">{{ number(report.tickets.sla[clock].met) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ t('reports.sla_breached') }}</dt>
                                <dd class="tabular-nums">{{ number(report.tickets.sla[clock].breached) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ t('reports.sla_pending') }}</dt>
                                <dd class="tabular-nums">{{ number(report.tickets.sla[clock].pending) }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div v-if="report.customers.length" class="rounded-md border p-4">
                        <p class="mb-3 text-sm font-medium">{{ t('reports.top_customers') }}</p>
                        <div v-for="customer in report.customers" :key="customer.name" class="mb-1.5 flex items-center gap-2 text-sm">
                            <span class="w-48 shrink-0 truncate" :title="customer.name">{{ customer.name }}</span>
                            <span class="h-2 flex-1 rounded bg-muted">
                                <span
                                    class="block h-full rounded bg-primary"
                                    :style="{ width: share(customer.tickets, report.customers[0].tickets) }"
                                />
                            </span>
                            <span class="w-10 text-right tabular-nums">{{ number(customer.tickets) }}</span>
                        </div>
                    </div>

                    <div v-if="report.technicians.length" class="overflow-x-auto rounded-md border">
                        <p class="px-4 pt-4 text-sm font-medium">{{ t('reports.technicians_title') }}</p>
                        <table class="mt-2 w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr>
                                    <th class="px-4 py-2 font-medium">{{ t('reports.technician') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('reports.tickets_count') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('reports.closed_count') }}</th>
                                    <th class="px-4 py-2 text-right font-medium">{{ t('reports.resolve_breached') }}</th>
                                    <th v-if="report.surveys" class="px-4 py-2 text-right font-medium">{{ t('reports.survey_average') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="technician in report.technicians" :key="technician.name" class="border-t">
                                    <td class="px-4 py-2">{{ technician.name }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ number(technician.tickets) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ number(technician.closed) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ number(technician.resolve_breached) }}</td>
                                    <td v-if="report.surveys" class="px-4 py-2 text-right tabular-nums">
                                        {{
                                            technician.average === null
                                                ? t('common.none')
                                                : `${technician.average.toFixed(2)} (${technician.answers})`
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- PM -->
            <section v-if="report.pm" class="space-y-4">
                <h3 class="text-base font-semibold">{{ t('reports.sections.pm') }}</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.pm_compliance') }}</p>
                        <p class="text-2xl font-semibold">{{ orNone(report.pm.compliance, '%') }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.pm_due') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.pm.due) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.pm_completed') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.pm.completed) }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('reports.pm_on_time') }} {{ number(report.pm.on_time) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.pm_overdue') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.pm.overdue) }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ t('reports.pm_scheduled') }} {{ number(report.pm.scheduled) }} · {{ t('reports.pm_in_progress') }}
                            {{ number(report.pm.in_progress) }}
                        </p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.pm_items_issue') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.pm.items.issue ?? 0) }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('reports.pm_items_ok') }} {{ number(report.pm.items.ok ?? 0) }}</p>
                    </div>
                </div>
            </section>

            <!-- Parts -->
            <section v-if="report.parts" class="space-y-4">
                <h3 class="text-base font-semibold">{{ t('reports.sections.parts') }}</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.parts_used') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.parts.used) }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ t('reports.parts_used_on_tickets') }} {{ number(report.parts.used_on_tickets) }}
                        </p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.parts_used_value') }}</p>
                        <p class="text-2xl font-semibold">{{ money(report.parts.used_value) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.parts_received') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.parts.received) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.parts_low') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.parts.low) }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('reports.today_note') }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.parts_out') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.parts.out) }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('reports.today_note') }}</p>
                    </div>
                </div>

                <div v-if="report.parts.top.length" class="overflow-x-auto rounded-md border">
                    <p class="px-4 pt-4 text-sm font-medium">{{ t('reports.top_parts') }}</p>
                    <table class="mt-2 w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('parts.code') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('parts.name') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('reports.quantity') }}</th>
                                <th class="px-4 py-2 text-right font-medium">{{ t('reports.value') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="part in report.parts.top" :key="part.code" class="border-t">
                                <td class="px-4 py-2 font-mono text-xs">{{ part.code }}</td>
                                <td class="px-4 py-2">{{ part.name }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ number(part.quantity) }} {{ part.unit }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ money(part.value) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Satisfaction -->
            <section v-if="report.surveys" class="space-y-4">
                <h3 class="text-base font-semibold">{{ t('reports.sections.surveys') }}</h3>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.survey_average') }}</p>
                        <p class="text-2xl font-semibold">
                            {{ report.surveys.average === null ? t('common.none') : report.surveys.average.toFixed(2) }}
                            <span class="text-sm font-normal text-muted-foreground">{{ t('surveys.summary.out_of', { max: 5 }) }}</span>
                        </p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.survey_rate') }}</p>
                        <p class="text-2xl font-semibold">{{ orNone(report.surveys.response_rate, '%') }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ t('surveys.summary.answered_of', { answered: report.surveys.answered, sent: report.surveys.sent }) }}
                        </p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="mb-1 text-xs text-muted-foreground">{{ t('surveys.summary.distribution') }}</p>
                        <div v-for="score in [5, 4, 3, 2, 1]" :key="score" class="flex items-center gap-2 text-xs">
                            <span class="w-3 text-right">{{ score }}</span>
                            <span class="h-2 flex-1 rounded bg-muted">
                                <span
                                    class="block h-full rounded bg-primary"
                                    :style="{
                                        width: report.surveys.scores[score] ? share(report.surveys.scores[score], report.surveys.answered) : '0',
                                    }"
                                />
                            </span>
                            <span class="w-6 text-right tabular-nums text-muted-foreground">{{ report.surveys.scores[score] ?? 0 }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Assets -->
            <section v-if="report.assets" class="space-y-4">
                <h3 class="text-base font-semibold">
                    {{ t('reports.sections.assets') }}
                    <span class="text-xs font-normal text-muted-foreground">· {{ t('reports.today_note') }}</span>
                </h3>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.assets_total') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.assets.total) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.warranty_expiring', { days: report.assets.expiring_days }) }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.assets.warranty_expiring) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">{{ t('reports.warranty_expired') }}</p>
                        <p class="text-2xl font-semibold">{{ number(report.assets.warranty_expired) }}</p>
                    </div>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-md border p-4">
                        <p class="mb-3 text-sm font-medium">{{ t('reports.by_status') }}</p>
                        <div v-for="(count, status) in report.assets.by_status" :key="status" class="mb-1.5 flex items-center gap-2 text-sm">
                            <span class="w-32 shrink-0">{{ t(`assets.statuses.${status}`) }}</span>
                            <span class="h-2 flex-1 rounded bg-muted">
                                <span class="block h-full rounded bg-primary" :style="{ width: count ? share(count, report.assets.total) : '0' }" />
                            </span>
                            <span class="w-10 text-right tabular-nums">{{ number(count) }}</span>
                        </div>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="mb-3 text-sm font-medium">{{ t('reports.by_category') }}</p>
                        <div v-for="category in report.assets.by_category" :key="category.name" class="mb-1.5 flex items-center gap-2 text-sm">
                            <span class="w-40 shrink-0 truncate" :title="category.name">{{ category.name }}</span>
                            <span class="h-2 flex-1 rounded bg-muted">
                                <span class="block h-full rounded bg-primary" :style="{ width: share(category.count, report.assets.total) }" />
                            </span>
                            <span class="w-10 text-right tabular-nums">{{ number(category.count) }}</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
