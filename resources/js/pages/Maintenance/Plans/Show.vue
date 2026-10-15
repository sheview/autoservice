<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

interface VisitRow {
    ulid: string;
    visit_no: string;
    round: number;
    status: string;
    items_count: number;
    period_starts_on: string;
    due_on: string;
    scheduled_on: string | null;
    completed_at: string | null;
    assignee: string | null;
    overdue: boolean;
}

const props = defineProps<{
    plan: {
        id: number;
        title: string;
        interval_months: number;
        notes: string | null;
        customer: string | null;
        contract: { id: number; contract_no: string; title: string; assets_count: number; can_view: boolean } | null;
        assignee: string | null;
        starts_on: string;
        ends_on: string;
        active: boolean;
        assets: { asset_code: string; name: string; location: string | null; floor: string | null; room: string | null }[];
    };
    visits: VisitRow[];
    upcoming: { round: number; period_starts_on: string; due_on: string; creates_on: string }[];
    can: { update: boolean; delete: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('pm_plans.title'), href: route('maintenance.plans.index') },
    { title: props.plan.title, href: route('maintenance.plans.show', props.plan.id) },
];

const destroy = () => {
    if (confirm(t('pm_plans.confirm_delete', { name: props.plan.title }))) {
        router.delete(route('maintenance.plans.destroy', props.plan.id));
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="plan.title" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="plan.title" :description="plan.customer ?? ''" />
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <a
                            :href="route('maintenance.reports.round', { plan: plan.id, from: plan.starts_on, to: plan.ends_on })"
                            target="_blank"
                            rel="noopener"
                            >{{ t('pm_plans.round_report') }}</a
                        >
                    </Button>
                    <Button variant="outline" as-child>
                        <a
                            :href="route('maintenance.reports.round', { plan: plan.id, from: plan.starts_on, to: plan.ends_on, print: 1 })"
                            target="_blank"
                            rel="noopener"
                            >{{ t('pm_plans.round_print') }}</a
                        >
                    </Button>
                    <Button v-if="can.update" variant="outline" as-child>
                        <Link :href="route('maintenance.plans.edit', plan.id)">{{ t('common.edit') }}</Link>
                    </Button>
                    <Button v-if="can.delete" variant="outline" class="text-destructive" @click="destroy">{{ t('common.delete') }}</Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="(page.props.errors as Record<string, string>).plan" />

            <dl class="grid gap-4 rounded-md border p-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_plans.contract') }}</dt>
                    <dd v-if="plan.contract">
                        <Link
                            v-if="plan.contract.can_view"
                            :href="route('contract.contracts.show', plan.contract.id)"
                            class="font-mono text-primary underline-offset-4 hover:underline"
                        >
                            {{ plan.contract.contract_no }}
                        </Link>
                        <span v-else class="font-mono">{{ plan.contract.contract_no }}</span>
                        <div class="text-xs text-muted-foreground">{{ t('pm_plans.assets_count', { count: plan.contract.assets_count }) }}</div>
                    </dd>
                    <dd v-else>{{ t('common.none') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_plans.period') }}</dt>
                    <dd>{{ plan.starts_on }} – {{ plan.ends_on }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_plans.interval') }}</dt>
                    <dd>{{ t('pm_plans.interval_months', { count: plan.interval_months }) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_plans.assignee') }}</dt>
                    <dd>{{ plan.assignee ?? t('pm_plans.no_assignee') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_plans.assets') }}</dt>
                    <dd>
                        {{
                            plan.assets.length
                                ? t('pm_plans.assets_picked', { count: plan.assets.length, total: plan.contract?.assets_count ?? plan.assets.length })
                                : t('pm_plans.assets_all', { total: plan.contract?.assets_count ?? 0 })
                        }}
                    </dd>
                    <dd v-if="!plan.active" class="text-xs font-semibold text-amber-700 dark:text-amber-300">{{ t('pm_plans.inactive') }}</dd>
                </div>
                <div v-if="plan.notes" class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">{{ t('pm_plans.notes') }}</dt>
                    <dd class="whitespace-pre-line">{{ plan.notes }}</dd>
                </div>
            </dl>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('pm_plans.visits') }}</h3>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('pm_visits.visit_no') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('pm_visits.period') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('pm_visits.scheduled_on') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('pm_visits.assignee') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('pm_visits.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="visit in visits" :key="visit.ulid" class="border-t">
                                <td class="whitespace-nowrap px-4 py-2">
                                    <Link
                                        :href="route('maintenance.visits.show', visit.ulid)"
                                        class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                    >
                                        {{ visit.visit_no }}
                                    </Link>
                                    <div class="text-xs text-muted-foreground">{{ t('pm_visits.round', { round: visit.round }) }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2">{{ visit.period_starts_on }} – {{ visit.due_on }}</td>
                                <td class="whitespace-nowrap px-4 py-2">{{ visit.scheduled_on ?? t('pm_visits.not_scheduled') }}</td>
                                <td class="px-4 py-2">{{ visit.assignee ?? t('pm_visits.unassigned') }}</td>
                                <td class="px-4 py-2"><PmVisitStatusBadge :status="visit.status" :overdue="visit.overdue" /></td>
                            </tr>
                            <tr v-if="visits.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('pm_plans.no_visits') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Rounds to come: their work orders are made ahead by the daily run -->
            <section v-if="upcoming.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('pm_plans.upcoming') }}</h3>
                <ul class="divide-y rounded-md border border-dashed text-sm">
                    <li v-for="round in upcoming" :key="round.round" class="flex flex-wrap justify-between gap-2 px-4 py-2 text-muted-foreground">
                        <span>{{ t('pm_visits.round', { round: round.round }) }} · {{ round.period_starts_on }} – {{ round.due_on }}</span>
                        <span class="text-xs">{{ t('pm_plans.creates_on', { date: round.creates_on }) }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="plan.assets.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('pm_plans.assets') }}</h3>
                <ul class="grid gap-1 text-sm sm:grid-cols-2">
                    <li v-for="asset in plan.assets" :key="asset.asset_code">
                        <span class="font-mono text-xs">{{ asset.asset_code }}</span> {{ asset.name }}
                        <span class="text-xs text-muted-foreground">{{
                            [asset.floor, asset.room].filter(Boolean).join(' · ') || asset.location
                        }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
