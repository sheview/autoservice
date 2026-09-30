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
    };
    visits: VisitRow[];
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

        <div class="max-w-5xl space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="plan.title" :description="plan.customer ?? ''" />
                <div class="flex gap-2">
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
        </div>
    </AppLayout>
</template>
