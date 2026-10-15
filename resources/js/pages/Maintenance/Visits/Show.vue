<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PmSharePanel, { type PmShare } from '@/components/PmSharePanel.vue';
import PmVisitItemCard, { type PmInstrumentOption, type PmItem } from '@/components/PmVisitItemCard.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import SignaturePad from '@/components/SignaturePad.vue';
import StepProgress, { type Step } from '@/components/StepProgress.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Signature {
    signer: string | null;
    position: string | null;
    signed_at: string | null;
}

interface VisitDetail {
    ulid: string;
    visit_no: string;
    title: string | null;
    round: number | null;
    status: string;
    summary: string | null;
    assignee_id: number | null;
    revision_note: string | null;
    revision_count: number;
    current_version: number;
    published_version: number;
    plan: { id: number; title: string; can_view: boolean } | null;
    customer: string | null;
    contract: { id: number; contract_no: string; title: string; can_view: boolean } | null;
    contract_assets_count: number;
    assignee: string | null;
    period_starts_on: string | null;
    due_on: string;
    scheduled_on: string | null;
    overdue: boolean;
    started_at: string | null;
    completed_at: string | null;
    reviewed_at: string | null;
    approved_at: string | null;
    published_at: string | null;
    closed_at: string | null;
    cancelled_at: string | null;
    signatures: { technician_signature: Signature | null; customer_signature: Signature | null };
}

interface HistoryEvent {
    id: number;
    type: string;
    from_status: string | null;
    to_status: string | null;
    note: string | null;
    data: Record<string, unknown> | null;
    user_name: string | null;
    at: string;
}

const props = defineProps<{
    visit: VisitDetail;
    items: PmItem[];
    assignees: { id: number; name: string }[] | null;
    priorities: string[];
    instruments: PmInstrumentOption[];
    notInspectedReasons: string[];
    moves: string[];
    needsNote: string[];
    signing: { technician: boolean; customer: boolean; review_step: boolean };
    events: HistoryEvent[];
    versions: { id: number; version: number; approved_by_name: string | null; at: string }[];
    can: { update: boolean; start: boolean; perform: boolean; cancel: boolean; openTicket: boolean; editItems: boolean; share: boolean };
    shares: PmShare[];
    shareDays: number;
    maxShareDays: number;
    assetSearch: string;
    candidates: { id: number; asset_code: string; name: string; category: string | null; serial_number: string | null; location: string | null }[];
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

// --- the other moves of the workflow: one button each; those that need a reason open a box first ---
const moving = ref<string | null>(null);
const move = useForm({ action: '', note: '' });
const allMoves = computed(() => [...props.moves, ...(props.can.cancel ? ['cancel'] : [])]);
const pickMove = (action: string) => {
    moving.value = action;
    move.action = action;
    move.note = '';
    move.clearErrors();
};
const sendMove = () =>
    move.post(route('maintenance.visits.move', props.visit.ulid), { preserveScroll: true, onSuccess: () => (moving.value = null) });
const moveVariant = (action: string) => (['return', 'reopen', 'cancel'].includes(action) ? 'outline' : 'default');

// --- hand the work in: summary and the signatures drawn on the screen ---
const submit = useForm({
    summary: props.visit.summary ?? '',
    technician_signature: null as string | null,
    customer_signature: null as string | null,
    customer_signer_name: props.visit.signatures.customer_signature?.signer ?? '',
    customer_signer_position: props.visit.signatures.customer_signature?.position ?? '',
});
const sendSubmit = () => submit.post(route('maintenance.visits.submit', props.visit.ulid), { preserveScroll: true });

const pending = computed(() => props.items.filter((item) => item.result === 'pending').length);

// --- step bar: plan → field work → review/approval → approved → published → closed ---
const stepBar = computed(() => {
    const v = props.visit;
    const at = (value: string | null) => (value ? dateTime(value) : null);
    const steps: Step[] = [
        { key: 'scheduled', label: t('steps.pm_visit.scheduled'), at: v.scheduled_on },
        { key: 'in_progress', label: t('steps.pm_visit.in_progress'), at: at(v.started_at) },
        { key: 'review', label: t('steps.pm_visit.review'), at: at(v.completed_at) },
        { key: 'approved', label: t('steps.pm_visit.approved'), at: at(v.approved_at) },
        { key: 'published', label: t('steps.pm_visit.published'), at: at(v.published_at) },
        { key: 'closed', label: t('steps.pm_visit.closed'), at: at(v.closed_at) },
    ];
    const index: Record<string, number> = {
        draft: 0,
        scheduled: 0,
        in_progress: 1,
        revision_required: 1,
        submitted: 2,
        pending_approval: 2,
        approved: 3,
        published: 4,
        closed: 5,
    };
    if (v.status === 'cancelled') {
        return { steps, current: v.started_at ? 1 : 0, state: 'cancelled' as const };
    }
    if (v.status === 'closed') {
        return { steps, current: 5, state: 'done' as const };
    }
    return { steps, current: index[v.status] ?? 0, state: v.status === 'revision_required' ? ('paused' as const) : ('active' as const) };
});

const heading = computed(() =>
    [props.visit.title, props.visit.round ? t('pm_visits.round', { round: props.visit.round }) : null].filter(Boolean).join(' · '),
);

// --- equipment: search the customer's assets and put the ticked ones on the work order ---
const search = ref(props.assetSearch);
const picked = ref<number[]>([]);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(
        () => router.reload({ only: ['candidates', 'assetSearch'], data: { asset_search: value || undefined }, preserveScroll: true } as never),
        300,
    );
});
const addPicked = () =>
    router.post(
        route('maintenance.visits.items.store', props.visit.ulid),
        { asset_ids: picked.value },
        { preserveScroll: true, onSuccess: () => ((picked.value = []), (search.value = '')) },
    );

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const textareaClass = 'shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="visit.visit_no" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="flex flex-wrap items-center gap-2 font-mono text-sm text-muted-foreground">
                        {{ visit.visit_no }}
                        <PmVisitStatusBadge :status="visit.status" :overdue="visit.overdue" />
                        <span v-if="visit.current_version" class="rounded-full bg-muted px-2 py-0.5 text-xs">{{
                            t('pm_visits.version', { version: visit.current_version })
                        }}</span>
                    </p>
                    <Heading :title="heading" :description="visit.customer ?? ''" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <a :href="route('maintenance.visits.pdf', visit.ulid)" target="_blank" rel="noopener">{{ t('pm_visits.pdf') }}</a>
                    </Button>
                    <Button v-if="can.start || can.perform" variant="outline" as-child>
                        <Link :href="route('maintenance.field.visit', visit.ulid)">{{ t('pm_visits.field_page') }}</Link>
                    </Button>
                    <Button v-if="can.start" @click="start">{{ t('pm_visits.start') }}</Button>
                    <Button v-for="action in allMoves" :key="action" :variant="moveVariant(action)" @click="pickMove(action)">
                        {{ t(`pm_visits.moves.${action}`) }}
                    </Button>
                </div>
            </div>

            <StepProgress :steps="stepBar.steps" :current="stepBar.current" :state="stepBar.state" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.visit ?? errors.photo ?? errors.action" />

            <!-- Sent back: what to fix -->
            <div
                v-if="visit.status === 'revision_required' && visit.revision_note"
                class="rounded-md border border-orange-300 bg-orange-50 p-4 text-sm dark:border-orange-900 dark:bg-orange-950/40"
            >
                <p class="font-semibold">{{ t('pm_visits.revision_note') }}</p>
                <p class="whitespace-pre-line">{{ visit.revision_note }}</p>
                <p class="mt-1 text-xs text-muted-foreground">{{ t('pm_visits.revision_count', { count: visit.revision_count }) }}</p>
            </div>

            <!-- Confirm a move (with its reason or a remark) -->
            <form v-if="moving" class="space-y-2 rounded-md border p-4" @submit.prevent="sendMove">
                <p class="font-semibold">{{ t(`pm_visits.moves.${moving}`) }}</p>
                <Label for="move_note">{{ needsNote.includes(moving) ? t('pm_visits.move_reason') : t('pm_visits.move_note') }}</Label>
                <textarea id="move_note" v-model="move.note" rows="2" :required="needsNote.includes(moving)" :class="textareaClass" />
                <InputError :message="move.errors.note ?? move.errors.action" />
                <div class="flex gap-2">
                    <Button size="sm" :variant="moving === 'cancel' ? 'destructive' : 'default'" :disabled="move.processing">{{
                        t('pm_visits.confirm')
                    }}</Button>
                    <Button size="sm" type="button" variant="outline" @click="moving = null">{{ t('common.cancel') }}</Button>
                </div>
            </form>

            <dl class="grid gap-4 rounded-md border p-4 text-sm sm:grid-cols-3">
                <div v-if="visit.plan">
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.plan') }}</dt>
                    <dd>
                        <Link
                            v-if="visit.plan.can_view"
                            :href="route('maintenance.plans.show', visit.plan.id)"
                            class="text-primary underline-offset-4 hover:underline"
                        >
                            {{ visit.plan.title }}
                        </Link>
                        <span v-else>{{ visit.plan.title }}</span>
                    </dd>
                </div>
                <div v-if="visit.contract">
                    <dt class="text-xs text-muted-foreground">{{ t('pm_visits.contract') }}</dt>
                    <dd>
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
                    <dt class="text-xs text-muted-foreground">{{ visit.period_starts_on ? t('pm_visits.period') : t('pm_visits.due_on') }}</dt>
                    <dd>{{ visit.period_starts_on ? `${visit.period_starts_on} – ${visit.due_on}` : visit.due_on }}</dd>
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
                <div v-for="(sig, key) in visit.signatures" v-show="sig" :key="key">
                    <dt class="text-xs text-muted-foreground">{{ t(`pm_visits.${key}`) }}</dt>
                    <dd>{{ sig ? t('pm_visits.signed_by', { name: sig.signer ?? '-', at: sig.signed_at ? dateTime(sig.signed_at) : '' }) : '' }}</dd>
                </div>
            </dl>

            <!-- Appointment and technician -->
            <form v-if="can.update && assignees" class="space-y-3 rounded-md border p-4" @submit.prevent="saveSchedule">
                <h3 class="text-sm font-semibold">{{ t('pm_visits.schedule') }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="scheduled_on">{{ t('pm_visits.scheduled_on') }}</Label>
                        <Input id="scheduled_on" v-model="schedule.scheduled_on as string" type="date" />
                        <InputError :message="schedule.errors.scheduled_on" />
                    </div>
                    <div class="grid content-start gap-2">
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

            <!-- Put more equipment on the work order -->
            <section v-if="can.editItems" class="space-y-2 rounded-md border p-4">
                <h3 class="text-sm font-semibold">{{ t('pm_visits.add_items') }}</h3>
                <Input v-model="search" type="search" :placeholder="t('pm_visits.add_items_hint')" />
                <template v-if="search">
                    <ul v-if="candidates.length" class="max-h-64 divide-y overflow-y-auto text-sm">
                        <li v-for="asset in candidates" :key="asset.id">
                            <label class="flex items-center gap-3 px-1 py-1.5">
                                <input v-model="picked" type="checkbox" :value="asset.id" class="size-4 rounded border-input" />
                                <span class="font-mono text-xs">{{ asset.asset_code }}</span>
                                <span>{{ asset.name }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    [asset.category, asset.serial_number, asset.location].filter(Boolean).join(' · ')
                                }}</span>
                            </label>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">{{ t('pm_visits.no_candidates') }}</p>
                </template>
                <Button v-if="picked.length" size="sm" @click="addPicked">{{ t('pm_visits.add_selected', { count: picked.length }) }}</Button>
                <InputError :message="errors.asset_ids ?? errors.item" />
            </section>

            <!-- Results per piece of equipment -->
            <section class="space-y-3">
                <h3 class="text-sm font-semibold">{{ t('pm_visits.items') }}</h3>
                <p v-if="['draft', 'scheduled'].includes(visit.status) && items.length === 0" class="text-sm text-muted-foreground">
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
                    :instruments="instruments"
                    :not-inspected-reasons="notInspectedReasons"
                />
            </section>

            <!-- Hand the work in -->
            <form v-if="can.perform" class="space-y-4 rounded-md border p-4" @submit.prevent="sendSubmit">
                <div>
                    <h3 class="font-semibold">{{ t('pm_visits.submit') }}</h3>
                    <p class="text-sm text-muted-foreground">
                        {{ signing.review_step ? t('pm_visits.submit_hint') : t('pm_visits.submit_hint_direct') }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="summary">{{ t('pm_visits.summary') }}</Label>
                    <textarea
                        id="summary"
                        v-model="submit.summary"
                        rows="3"
                        :placeholder="t('pm_visits.summary_placeholder')"
                        :class="textareaClass"
                    />
                    <InputError :message="submit.errors.summary" />
                </div>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label>
                            {{ t('pm_visits.technician_signature') }}
                            <span class="text-xs text-muted-foreground">{{
                                signing.technician ? t('pm_visits.required') : t('pm_visits.optional')
                            }}</span>
                        </Label>
                        <p v-if="visit.signatures.technician_signature" class="text-xs text-muted-foreground">{{ t('pm_visits.signed_before') }}</p>
                        <SignaturePad v-model="submit.technician_signature" />
                        <InputError :message="submit.errors.technician_signature" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label>
                            {{ t('pm_visits.customer_signature') }}
                            <span class="text-xs text-muted-foreground">{{
                                signing.customer ? t('pm_visits.required') : t('pm_visits.optional')
                            }}</span>
                        </Label>
                        <p v-if="visit.signatures.customer_signature" class="text-xs text-muted-foreground">{{ t('pm_visits.signed_before') }}</p>
                        <SignaturePad v-model="submit.customer_signature" />
                        <InputError :message="submit.errors.customer_signature" />
                        <div class="grid gap-2 sm:grid-cols-2">
                            <Input v-model="submit.customer_signer_name" :placeholder="t('pm_visits.customer_signer_name')" />
                            <Input v-model="submit.customer_signer_position" :placeholder="t('pm_visits.customer_signer_position')" />
                        </div>
                        <InputError :message="submit.errors.customer_signer_name" />
                    </div>
                </div>
                <Button :disabled="submit.processing || pending > 0">{{ t('pm_visits.submit') }}</Button>
                <p v-if="pending > 0" class="text-xs text-muted-foreground">
                    {{ t('pm_visits.progress_value', { done: items.length - pending, total: items.length }) }}
                </p>
            </form>

            <!-- Customers' links to the published report -->
            <PmSharePanel
                v-if="can.share || shares.length"
                :visit-ulid="visit.ulid"
                :shares="shares"
                :can-share="can.share"
                :share-days="shareDays"
                :max-share-days="maxShareDays"
            />

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Report versions -->
                <section v-if="versions.length" class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('pm_visits.versions') }}</h3>
                    <ul class="divide-y rounded-md border text-sm">
                        <li v-for="version in versions" :key="version.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                            <span>
                                <span class="font-medium">{{ t('pm_visits.version', { version: version.version }) }}</span>
                                <span
                                    v-if="version.version === visit.published_version"
                                    class="ml-2 rounded-full bg-teal-100 px-2 py-0.5 text-xs text-teal-800 dark:bg-teal-950 dark:text-teal-200"
                                    >{{ t('pm_visits.published_badge') }}</span
                                >
                                <span class="ml-2 text-xs text-muted-foreground">{{
                                    t('pm_visits.version_by', { name: version.approved_by_name ?? '-', at: dateTime(version.at) })
                                }}</span>
                            </span>
                            <a
                                :href="route('maintenance.visits.pdf', { visit: visit.ulid, version: version.version })"
                                target="_blank"
                                rel="noopener"
                                class="text-xs text-primary underline-offset-4 hover:underline"
                                >PDF</a
                            >
                        </li>
                    </ul>
                </section>

                <!-- History -->
                <section v-if="events.length" class="space-y-2" :class="{ 'lg:col-span-2': !versions.length }">
                    <h3 class="text-sm font-semibold">{{ t('pm_visits.history') }}</h3>
                    <ul class="divide-y rounded-md border text-sm">
                        <li v-for="event in events" :key="event.id" class="px-4 py-2">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium">{{ t(`pm_visits.events.${event.type}`) }}</span>
                                <span class="text-xs text-muted-foreground">{{ event.user_name ?? '-' }} · {{ dateTime(event.at) }}</span>
                            </div>
                            <p v-if="event.note" class="whitespace-pre-line text-muted-foreground">{{ event.note }}</p>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
