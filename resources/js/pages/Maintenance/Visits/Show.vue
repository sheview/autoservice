<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PmVisitItemCard, { type PmItem } from '@/components/PmVisitItemCard.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface VisitDetail {
    ulid: string;
    visit_no: string;
    round: number;
    status: string;
    summary: string | null;
    assignee_id: number | null;
    plan: { id: number; title: string; can_view: boolean } | null;
    customer: string | null;
    contract: { id: number; contract_no: string; title: string; can_view: boolean } | null;
    contract_assets_count: number;
    assignee: string | null;
    period_starts_on: string;
    due_on: string;
    scheduled_on: string | null;
    overdue: boolean;
    started_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
}

const props = defineProps<{
    visit: VisitDetail;
    items: PmItem[];
    assignees: { id: number; name: string }[] | null;
    priorities: string[];
    can: { update: boolean; start: boolean; perform: boolean; cancel: boolean; openTicket: boolean };
}>();

const page = usePage<SharedData>();
const errors = computed(() => page.props.errors as Record<string, string>);
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('pm_visits.title'), href: route('maintenance.visits.index') },
    { title: props.visit.visit_no, href: route('maintenance.visits.show', props.visit.ulid) },
];

const schedule = useForm({ scheduled_on: props.visit.scheduled_on, assignee_id: props.visit.assignee_id });
const saveSchedule = () => schedule.put(route('maintenance.visits.update', props.visit.ulid), { preserveScroll: true });

const start = () => router.post(route('maintenance.visits.start', props.visit.ulid), {}, { preserveScroll: true });

const complete = useForm({ summary: '' });
const submitComplete = () => complete.post(route('maintenance.visits.complete', props.visit.ulid), { preserveScroll: true });

const cancelling = ref(false);
const cancel = useForm({ reason: '' });
const submitCancel = () =>
    cancel.post(route('maintenance.visits.cancel', props.visit.ulid), { preserveScroll: true, onSuccess: () => (cancelling.value = false) });

const pending = computed(() => props.items.filter((item) => item.result === 'pending').length);

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="visit.visit_no" />

        <div class="max-w-5xl space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="flex items-center gap-2 font-mono text-sm text-muted-foreground">
                        {{ visit.visit_no }}
                        <PmVisitStatusBadge :status="visit.status" :overdue="visit.overdue" />
                    </p>
                    <Heading
                        :title="`${visit.plan?.title ?? ''} · ${t('pm_visits.round', { round: visit.round })}`"
                        :description="visit.customer ?? ''"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.start" @click="start">{{ t('pm_visits.start') }}</Button>
                    <Button v-if="can.cancel && !cancelling" variant="outline" @click="cancelling = true">{{ t('pm_visits.cancel') }}</Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.visit ?? errors.photo" />

            <form v-if="cancelling" class="space-y-2 rounded-md border p-4" @submit.prevent="submitCancel">
                <Label for="reason">{{ t('pm_visits.cancel_reason') }}</Label>
                <textarea
                    id="reason"
                    v-model="cancel.reason"
                    rows="2"
                    required
                    :placeholder="t('pm_visits.reason_placeholder')"
                    class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                />
                <InputError :message="cancel.errors.reason" />
                <div class="flex gap-2">
                    <Button size="sm" variant="destructive" :disabled="cancel.processing">{{ t('pm_visits.confirm') }}</Button>
                    <Button size="sm" type="button" variant="outline" @click="cancelling = false">{{ t('common.cancel') }}</Button>
                </div>
            </form>

            <dl class="grid gap-4 rounded-md border p-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.plan') }}</dt>
                    <dd>
                        <span v-if="visit.plan && !visit.plan.can_view">{{ visit.plan.title }}</span>
                        <Link
                            v-else-if="visit.plan"
                            :href="route('maintenance.plans.show', visit.plan.id)"
                            class="text-primary underline-offset-4 hover:underline"
                        >
                            {{ visit.plan.title }}
                        </Link>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.contract') }}</dt>
                    <dd v-if="visit.contract">
                        <Link
                            v-if="visit.contract.can_view"
                            :href="route('contract.contracts.show', visit.contract.id)"
                            class="font-mono text-primary underline-offset-4 hover:underline"
                        >
                            {{ visit.contract.contract_no }}
                        </Link>
                        <span v-else class="font-mono">{{ visit.contract.contract_no }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.period') }}</dt>
                    <dd>{{ visit.period_starts_on }} – {{ visit.due_on }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.scheduled_on') }}</dt>
                    <dd>{{ visit.scheduled_on ?? t('pm_visits.not_scheduled') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.assignee') }}</dt>
                    <dd>{{ visit.assignee ?? t('pm_visits.unassigned') }}</dd>
                </div>
                <div v-if="visit.started_at">
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.started_at') }}</dt>
                    <dd>{{ dateTime(visit.started_at) }}</dd>
                </div>
                <div v-if="visit.completed_at">
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.completed_at') }}</dt>
                    <dd>{{ dateTime(visit.completed_at) }}</dd>
                </div>
                <div v-if="visit.cancelled_at">
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.cancelled_at') }}</dt>
                    <dd>{{ dateTime(visit.cancelled_at) }}</dd>
                </div>
                <div v-if="visit.summary" class="sm:col-span-3">
                    <dt class="text-xs text-muted-foreground">
                        {{ visit.status === 'cancelled' ? t('pm_visits.cancel_reason') : t('pm_visits.summary') }}
                    </dt>
                    <dd class="whitespace-pre-line">{{ visit.summary }}</dd>
                </div>
            </dl>

            <!-- Appointment and technician -->
            <form v-if="can.update && assignees" class="space-y-3 rounded-md border p-4" @submit.prevent="saveSchedule">
                <h3 class="text-sm font-semibold">{{ t('pm_visits.schedule') }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="scheduled_on">{{ t('pm_visits.scheduled_on') }}</Label>
                        <Input id="scheduled_on" v-model="schedule.scheduled_on as string" type="date" />
                        <InputError :message="schedule.errors.scheduled_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="assignee_id">{{ t('pm_visits.assignee') }}</Label>
                        <select id="assignee_id" v-model="schedule.assignee_id" :class="selectClass">
                            <option :value="null">{{ t('pm_visits.unassigned') }}</option>
                            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <InputError :message="schedule.errors.assignee_id" />
                    </div>
                </div>
                <Button size="sm" variant="outline" :disabled="schedule.processing">{{ t('pm_visits.save_schedule') }}</Button>
            </form>

            <!-- Results per asset -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold">{{ t('pm_visits.items') }}</h3>
                <p v-if="visit.status === 'scheduled'" class="text-sm text-muted-foreground">
                    {{ visit.contract_assets_count ? t('pm_visits.start_hint', { count: visit.contract_assets_count }) : t('pm_visits.no_assets') }}
                </p>
                <p v-else-if="items.length === 0" class="text-sm text-muted-foreground">{{ t('pm_visits.no_assets') }}</p>
                <PmVisitItemCard
                    v-for="item in items"
                    :key="item.id"
                    :item="item"
                    :visit-ulid="visit.ulid"
                    :editable="can.perform"
                    :can-open-ticket="can.openTicket"
                    :priorities="priorities"
                />
            </section>

            <!-- Close the round -->
            <form v-if="can.perform" class="space-y-2 rounded-md border p-4" @submit.prevent="submitComplete">
                <Label for="summary">{{ t('pm_visits.summary') }}</Label>
                <textarea
                    id="summary"
                    v-model="complete.summary"
                    rows="3"
                    :placeholder="t('pm_visits.summary_placeholder')"
                    class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                />
                <InputError :message="complete.errors.summary" />
                <Button :disabled="complete.processing || pending > 0">{{ t('pm_visits.complete') }}</Button>
            </form>
        </div>
    </AppLayout>
</template>
