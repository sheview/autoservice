<script setup lang="ts">
import ColumnChart from '@/components/ColumnChart.vue';
import PlatformOverview from '@/components/PlatformOverview.vue';
import StarRating from '@/components/StarRating.vue';
import TicketPriorityBadge from '@/components/TicketPriorityBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// The home page: what needs attention now. Each card is null when the user may not see it.
const props = defineProps<{
    tickets: {
        open: number;
        mine: number;
        unassigned: number;
        breached: number;
        due_soon: number;
        recent: { ulid: string; ticket_no: string; title: string; status: string; priority: string; created_at: string }[];
    } | null;
    pm: { overdue: number; this_month: number; mine: number } | null;
    contracts: { expiring: number } | null;
    parts: { low: number; out: number } | null;
    surveys: { average: number | null; answered: number; sent: number } | null;
    trends: {
        year: number;
        years: number[];
        tickets: { monthly: { opened: number[]; closed: number[] }; yearly: { years: number[]; opened: number[]; closed: number[] } } | null;
        assets: {
            monthly: { hardware: number[]; software: number[] };
            yearly: { years: number[]; hardware: number[]; software: number[] };
        } | null;
    } | null;
    // The platform's own workspace: its customer companies (null inside a company).
    platform: InstanceType<typeof PlatformOverview>['$props']['platform'] | null;
    can: { createTicket: boolean; reports: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.dashboard'), href: route('dashboard') }];

interface Tile {
    key: string;
    label: string;
    value: string;
    href: string;
    alert?: boolean;
}

// One tile per figure; a figure that needs action now is highlighted when above zero.
const tiles = computed<Tile[]>(() => {
    const list: Tile[] = [];
    const tickets = props.tickets;
    if (tickets) {
        list.push(
            { key: 'open', label: t('dashboard.tickets_open'), value: String(tickets.open), href: route('service.tickets.index') },
            {
                key: 'mine',
                label: t('dashboard.tickets_mine'),
                value: String(tickets.mine),
                href: route('service.tickets.index', { assignee: 'me' }),
            },
            {
                key: 'breached',
                label: t('dashboard.tickets_breached'),
                value: String(tickets.breached),
                href: route('service.tickets.index', { sla: 'breached' }),
                alert: tickets.breached > 0,
            },
            {
                key: 'unassigned',
                label: t('dashboard.tickets_unassigned'),
                value: String(tickets.unassigned),
                href: route('service.tickets.index', { assignee: 'none' }),
                alert: tickets.unassigned > 0,
            },
        );
    }
    if (props.pm) {
        list.push(
            {
                key: 'pm_overdue',
                label: t('dashboard.pm_overdue'),
                value: String(props.pm.overdue),
                href: route('maintenance.visits.index', { status: 'overdue' }),
                alert: props.pm.overdue > 0,
            },
            { key: 'pm_month', label: t('dashboard.pm_this_month'), value: String(props.pm.this_month), href: route('maintenance.visits.index') },
        );
    }
    if (props.contracts) {
        list.push({
            key: 'contracts',
            label: t('dashboard.contracts_expiring'),
            value: String(props.contracts.expiring),
            href: route('contract.contracts.index', { phase: 'expiring' }),
            alert: props.contracts.expiring > 0,
        });
    }
    if (props.parts) {
        list.push({
            key: 'parts',
            label: t('dashboard.parts_low'),
            value: String(props.parts.low + props.parts.out),
            href: route('inventory.parts.index', { sort: 'qty_on_hand' }),
            alert: props.parts.out > 0,
        });
    }
    return list;
});

// --- charts: one calendar year per month, or the years up to it ----------------
const period = ref<'monthly' | 'yearly'>('monthly');
const MONTHS = Array.from({ length: 12 }, (_, m) => new Date(2000, m, 1).toLocaleString('th-TH', { month: 'short' }));

const pickYear = (event: Event) => {
    const year = Number((event.target as HTMLSelectElement).value);
    const isThisYear = year === props.trends?.years[0];
    router.get(route('dashboard'), isThisYear ? {} : { year }, { only: ['trends'], preserveState: true, preserveScroll: true, replace: true });
};

const charts = computed(() => {
    const trends = props.trends;
    if (!trends) {
        return null;
    }
    const monthly = period.value === 'monthly';
    const yearly = trends.tickets?.yearly.years ?? trends.assets?.yearly.years ?? [];
    const subtitle = monthly
        ? t('dashboard.charts.monthly_of', { year: trends.year })
        : t('dashboard.charts.yearly_of', { from: yearly[0], to: yearly[yearly.length - 1] });
    const categories = monthly ? MONTHS : yearly.map(String);

    return {
        tickets: trends.tickets && {
            title: `${t('dashboard.charts.tickets')} · ${subtitle}`,
            categories,
            series: [
                { key: 'opened', label: t('dashboard.charts.opened'), values: trends.tickets[period.value].opened },
                { key: 'closed', label: t('dashboard.charts.closed'), values: trends.tickets[period.value].closed },
            ],
        },
        assets: trends.assets && {
            title: `${t('dashboard.charts.assets')} · ${subtitle}`,
            categories,
            series: (['hardware', 'software'] as const).map((type) => ({
                key: type,
                label: t(`asset_categories.asset_types.${type}`),
                values: trends.assets![period.value][type],
            })),
        },
    };
});
</script>

<template>
    <Head :title="t('nav.dashboard')" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">{{ t('dashboard.hello', { name: page.props.auth.user.name }) }}</h1>
                    <p class="text-sm text-muted-foreground">{{ page.props.tenant?.name }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.reports" variant="outline" as-child>
                        <Link :href="route('reporting.reports.index')">{{ t('nav.reports') }}</Link>
                    </Button>
                    <Button v-if="can.createTicket" as-child>
                        <Link :href="route('service.tickets.create')">{{ t('tickets.create') }}</Link>
                    </Button>
                    <Button v-if="platform?.can.manage" as-child>
                        <Link :href="route('platform.tenants.create')">{{ t('tenants.create') }}</Link>
                    </Button>
                </div>
            </div>

            <div v-if="tiles.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="tile in tiles"
                    :key="tile.key"
                    :href="tile.href"
                    class="rounded-md border p-4 transition-colors hover:bg-muted/40"
                    :class="{ 'border-red-300 dark:border-red-900': tile.alert }"
                >
                    <p class="text-xs text-muted-foreground">{{ tile.label }}</p>
                    <p class="text-2xl font-semibold" :class="{ 'text-red-700 dark:text-red-400': tile.alert }">{{ tile.value }}</p>
                </Link>
            </div>

            <section v-if="trends && charts" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold">{{ t('dashboard.charts.title') }}</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-md border p-0.5" role="group">
                            <button
                                v-for="option in ['monthly', 'yearly'] as const"
                                :key="option"
                                type="button"
                                class="rounded px-3 py-1 text-sm transition-colors"
                                :class="period === option ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
                                :aria-pressed="period === option"
                                @click="period = option"
                            >
                                {{ t(`dashboard.charts.${option}`) }}
                            </button>
                        </div>
                        <select
                            :value="trends.year"
                            :aria-label="t('dashboard.charts.year')"
                            class="shadow-xs h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            @change="pickYear"
                        >
                            <option v-for="year in trends.years" :key="year" :value="year">{{ year }}</option>
                        </select>
                    </div>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <ColumnChart v-if="charts.tickets" v-bind="charts.tickets" />
                    <div v-if="charts.assets" class="space-y-1">
                        <ColumnChart v-bind="charts.assets" stacked />
                        <p class="text-xs text-muted-foreground">{{ t('dashboard.charts.assets_hint') }}</p>
                    </div>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-3">
                <section v-if="tickets" class="space-y-2 lg:col-span-2">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold">{{ t('dashboard.recent_tickets') }}</h2>
                        <Link :href="route('service.tickets.index')" class="text-sm text-primary underline-offset-4 hover:underline">
                            {{ t('dashboard.view_all') }}
                        </Link>
                    </div>
                    <ul v-if="tickets.recent.length" class="divide-y rounded-md border text-sm">
                        <li v-for="ticket in tickets.recent" :key="ticket.ulid">
                            <Link
                                :href="route('service.tickets.show', ticket.ulid)"
                                class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 hover:bg-muted/40"
                            >
                                <span class="min-w-0">
                                    <span class="font-mono text-xs text-muted-foreground">{{ ticket.ticket_no }}</span>
                                    <span class="ml-2">{{ ticket.title }}</span>
                                    <span class="ml-2 text-xs text-muted-foreground">{{ dateTime(ticket.created_at) }}</span>
                                </span>
                                <span class="flex items-center gap-2">
                                    <TicketPriorityBadge :priority="ticket.priority" />
                                    <TicketStatusBadge :status="ticket.status" />
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="rounded-md border px-4 py-6 text-center text-sm text-muted-foreground">{{ t('dashboard.no_open_tickets') }}</p>
                </section>

                <section v-if="surveys" class="space-y-2">
                    <h2 class="text-sm font-semibold">{{ t('dashboard.satisfaction') }}</h2>
                    <div class="rounded-md border p-4">
                        <p class="text-2xl font-semibold">
                            {{ surveys.average === null ? t('common.none') : surveys.average.toFixed(2) }}
                            <span class="text-sm font-normal text-muted-foreground">{{ t('surveys.summary.out_of', { max: 5 }) }}</span>
                        </p>
                        <StarRating :model-value="surveys.average === null ? null : Math.round(surveys.average)" />
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ t('surveys.summary.answered_of', { answered: surveys.answered, sent: surveys.sent }) }} ·
                            {{ t('dashboard.this_month') }}
                        </p>
                    </div>
                </section>
            </div>

            <PlatformOverview v-if="platform" :platform="platform" />

            <p v-else-if="!tiles.length && !tickets" class="rounded-md border px-4 py-8 text-center text-sm text-muted-foreground">
                {{ page.props.tenant?.is_platform ? t('dashboard.platform_hint') : t('dashboard.nothing') }}
            </p>
        </div>
    </AppLayout>
</template>
