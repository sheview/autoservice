<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SharedRequestList, { type SharedRequestRow } from '@/components/SharedRequestList.vue';
import SlaBadge from '@/components/SlaBadge.vue';
import StarRating from '@/components/StarRating.vue';
import StepProgress, { type Step } from '@/components/StepProgress.vue';
import StockMovementTypeBadge from '@/components/StockMovementTypeBadge.vue';
import TicketAppointment from '@/components/TicketAppointment.vue';
import TicketForwardPanel, { type TicketForwards } from '@/components/TicketForwardPanel.vue';
import TicketIpPanel from '@/components/TicketIpPanel.vue';
import TicketPhotos from '@/components/TicketPhotos.vue';
import TicketPriorityBadge from '@/components/TicketPriorityBadge.vue';
import TicketReviewPanel from '@/components/TicketReviewPanel.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import TicketTrackingLink from '@/components/TicketTrackingLink.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface TicketDetail {
    ulid: string;
    appointment_at: string | null;
    ticket_no: string;
    title: string;
    description: string | null;
    status: string;
    priority: string;
    source: string;
    contact_name: string | null;
    contact_phone: string | null;
    service_window: string | null;
    response_minutes: number | null;
    resolve_minutes: number | null;
    hold_minutes: number;
    customer: string | null;
    asset: { ulid: string; asset_code: string; name: string; can_view: boolean } | null;
    // The device as kept on the ticket (copied from the asset, or typed when not registered).
    device: {
        device_name: string | null;
        device_brand: string | null;
        device_model: string | null;
        device_serial: string | null;
        device_serial_unknown: boolean;
        device_location: string | null;
        device_ip: string | null;
        property_no: string | null;
    };
    warranty: {
        status: string | null;
        expires_on: string | null;
        checked_by: string | null;
        checked_at: string | null;
        // What the asset register says, for staff to confirm.
        asset_expires_on: string | null;
    };
    // Staff only; extra_cost in baht.
    report: { cause: string | null; extra_cost: string | null; approver_name: string | null } | null;
    contract: { id: number; contract_no: string; title: string; can_view: boolean } | null;
    branch: string | null;
    assignee_id: number | null;
    assignee: string | null;
    reporter: string | null;
    sla: { response: string; resolve: string };
    response_due_at: string | null;
    resolve_due_at: string | null;
    responded_at: string | null;
    on_hold_since: string | null;
    resolved_at: string | null;
    closed_at: string | null;
    cancelled_at: string | null;
    created_at: string;
}

interface TicketEvent {
    id: number;
    type: string;
    from_status: string | null;
    to_status: string | null;
    body: string | null;
    is_internal: boolean;
    user_name: string | null;
    at: string;
}

interface TicketPart {
    part_id: number;
    code: string;
    name: string;
    unit: string;
    quantity: number;
    // How it left stock for this ticket: issue, loan, spare.
    types: string[];
}

interface PartOption {
    id: number;
    code: string;
    name: string;
    unit: string;
    qty_on_hand: number;
}

interface TicketSurvey {
    answered: boolean;
    score: number | null;
    comment: string | null;
    answered_name: string | null;
    answered_at: string | null;
    canAnswer: boolean;
    // Staff keying in the score ticked on the printed job sheet.
    canPaper: boolean;
    // The public link and its QR code: only for staff, while the survey is unanswered.
    url: string | null;
    qr: string | null;
}

const props = defineProps<{
    ticket: TicketDetail;
    events: TicketEvent[];
    actions: string[];
    needsComment: string[];
    assignees: { id: number; name: string }[] | null;
    parts: { items: TicketPart[]; options: PartOption[]; types: string[]; canIssue: boolean; canReturn: boolean } | null;
    survey: TicketSurvey | null;
    attachments: Attachment[];
    // IP management: null = not shown to this user.
    ip: {
        current: { id: number; ulid: string; ip: string; status: string; cidr: string | null } | null;
        suggested: { key: string; ip: string; cidr: string | null } | null;
        can_change: boolean;
    } | null;
    sharedRequests: SharedRequestRow[];
    forwards: TicketForwards | null;
    tracking: { url: string; can_renew: boolean } | null;
    review: {
        contracts: { contract_no: string; title: string; service_window: string }[];
        warranty_expires_on: string | null;
        contact_email: string | null;
    } | null;
    photos: { url: string; stage: string | null }[];
    signature: { url: string; signer: string | null } | null;
    askOthersUrl: string | null;
    ipChoices?: { key: string; ip: string; cidr: string; status: string; hostname: string | null }[];
    can: { update: boolean; comment: boolean; internalNotes: boolean; deleteAttachments: boolean; checkWarranty: boolean; report: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('tickets.title'), href: route('service.tickets.index') },
    { title: props.ticket.ticket_no, href: route('service.tickets.show', props.ticket.ulid) },
];

// --- workflow ------------------------------------------------------------------
const move = useForm({ action: '', comment: '' });
const pending = ref<string | null>(null);

const press = (action: string) => {
    if (props.needsComment.includes(action)) {
        pending.value = action;
        move.comment = '';
        return;
    }
    move.action = action;
    move.comment = '';
    move.post(route('service.tickets.move', props.ticket.ulid), { preserveScroll: true });
};

const confirmPending = () => {
    move.action = pending.value ?? '';
    move.post(route('service.tickets.move', props.ticket.ulid), {
        preserveScroll: true,
        onSuccess: () => (pending.value = null),
    });
};

const actionClass = (action: string) => (['cancel', 'reject', 'hold'].includes(action) ? 'outline' : 'default');

// --- assign / comment ----------------------------------------------------------
const assign = useForm({ assignee_id: props.ticket.assignee_id });
const saveAssignee = () => assign.post(route('service.tickets.assign', props.ticket.ulid), { preserveScroll: true });

const comment = useForm({ body: '', is_internal: false });
const addComment = () =>
    comment.post(route('service.tickets.comments.store', props.ticket.ulid), {
        preserveScroll: true,
        onSuccess: () => comment.reset(),
    });

// --- warranty check (before work starts) ---------------------------------------
const today = new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Bangkok' });
const assetWarranty = props.ticket.warranty.asset_expires_on;
// What the asset register suggests; staff still confirm it.
const suggestedWarranty = assetWarranty ? (assetWarranty >= today ? 'in_warranty' : 'out_of_warranty') : null;
const warranty = useForm({
    warranty_status: props.ticket.warranty.status ?? suggestedWarranty ?? '',
    warranty_expires_on: props.ticket.warranty.expires_on ?? assetWarranty ?? '',
});
const editingWarranty = ref(props.ticket.warranty.status === null);
const saveWarranty = () =>
    warranty.post(route('service.tickets.warranty', props.ticket.ulid), {
        preserveScroll: true,
        onSuccess: () => (editingWarranty.value = false),
    });

// --- repair report (printed on the job sheet) ----------------------------------
const report = useForm({
    cause: props.ticket.report?.cause ?? '',
    extra_cost: props.ticket.report?.extra_cost ?? '',
    approver_name: props.ticket.report?.approver_name ?? '',
});
const saveReport = () => report.post(route('service.tickets.report', props.ticket.ulid), { preserveScroll: true });

const deviceFacts = computed(() => {
    const device = props.ticket.device;
    return [
        { label: 'tickets.device_name', value: device.device_name },
        { label: 'tickets.device_brand', value: device.device_brand },
        { label: 'tickets.device_model', value: device.device_model },
        { label: 'tickets.device_serial', value: device.device_serial_unknown ? t('tickets.device_serial_unknown') : device.device_serial },
        { label: 'tickets.property_no', value: device.property_no },
        { label: 'tickets.device_location', value: device.device_location },
        { label: 'tickets.device_ip', value: device.device_ip },
    ];
});

// --- satisfaction survey (Survey module) ---------------------------------------
const paperOpen = ref(false);
const paper = useForm({ score: null as number | null, name: props.ticket.contact_name ?? '', comment: '' });
const submitPaper = () => paper.post(route('service.tickets.survey.paper', props.ticket.ulid), { preserveScroll: true });

const rating = useForm({ score: null as number | null, comment: '' });
const submitRating = () => rating.post(route('service.tickets.survey.store', props.ticket.ulid), { preserveScroll: true });

const copied = ref(false);
const copyLink = async () => {
    if (props.survey?.url) {
        await navigator.clipboard.writeText(props.survey.url);
        copied.value = true;
    }
};

// --- spare parts (Inventory module) --------------------------------------------
const issue = useForm({ part_id: null as number | null, quantity: 1, type: 'issue' });
const issuePart = () =>
    issue.post(route('service.tickets.parts.store', props.ticket.ulid), {
        preserveScroll: true,
        onSuccess: () => issue.reset(),
    });

const returnPart = (part: TicketPart) => {
    const answer = prompt(t('ticket_parts.confirm_return', { name: part.name, qty: part.quantity }), String(part.quantity));
    const quantity = Number(answer);
    if (answer === null || !Number.isInteger(quantity) || quantity < 1) {
        return;
    }
    router.post(route('service.tickets.parts.return', props.ticket.ulid), { part_id: part.part_id, quantity }, { preserveScroll: true });
};

// --- display -------------------------------------------------------------------
const hours = (minutes: number | null) => (minutes === null ? '' : t('contracts.hours', { hours: minutes / 60 }));

const eventText = (event: TicketEvent) => {
    switch (event.type) {
        case 'created':
            return t('tickets.events.created');
        case 'assigned':
            return event.body ? t('tickets.events.assigned', { name: event.body }) : t('tickets.events.unassigned');
        case 'status':
            return t('tickets.events.status', {
                from: t(`tickets.statuses.${event.from_status}`),
                to: t(`tickets.statuses.${event.to_status}`),
            });
        case 'updated':
            return t('tickets.events.updated', { fields: event.body ?? '' });
        case 'warranty':
            return `${t('tickets.events.warranty')}: ${event.body ?? ''}`;
        default:
            return t('tickets.events.comment');
    }
};

const showBody = (event: TicketEvent) => event.body && ['comment', 'status'].includes(event.type);

// --- step bar ------------------------------------------------------------------
// On hold and cancelled are side tracks: show them on the step where the work stopped.
const STEP_KEYS = ['new', 'assigned', 'in_progress', 'resolved', 'closed'];

const lastStatusEvent = (status: string) => props.events.filter((event) => event.type === 'status' && event.to_status === status).at(-1);
const lastAssigned = () => props.events.filter((event) => event.type === 'assigned' && event.body).at(-1);

const stepBar = computed(() => {
    const at = (iso: string | null | undefined) => (iso ? dateTime(iso) : null);
    const steps: Step[] = [
        { key: 'new', label: t('steps.ticket.new'), at: at(props.ticket.created_at) },
        { key: 'assigned', label: t('steps.ticket.assigned'), at: at(lastAssigned()?.at) },
        { key: 'in_progress', label: t('steps.ticket.in_progress'), at: at(props.ticket.responded_at) },
        { key: 'resolved', label: t('steps.ticket.resolved'), at: at(props.ticket.resolved_at) },
        { key: 'closed', label: t('steps.ticket.closed'), at: at(props.ticket.closed_at) },
    ];
    if (props.survey) {
        steps.push({ key: 'survey', label: t('steps.ticket.survey'), at: props.survey.answered ? at(props.survey.answered_at) : null });
    }

    const status = props.ticket.status;
    if (status === 'on_hold') {
        return { steps, current: STEP_KEYS.indexOf('in_progress'), state: 'paused' as const };
    }
    if (status === 'cancelled') {
        const from = lastStatusEvent('cancelled')?.from_status ?? 'new';
        return { steps, current: Math.max(0, STEP_KEYS.indexOf(from === 'on_hold' ? 'in_progress' : from)), state: 'cancelled' as const };
    }
    if (status === 'closed' && props.survey) {
        return { steps, current: steps.length - 1, state: props.survey.answered ? ('done' as const) : ('active' as const) };
    }

    return { steps, current: STEP_KEYS.indexOf(status), state: status === 'closed' ? ('done' as const) : ('active' as const) };
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${ticket.ticket_no} ${ticket.title}`" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="flex items-center gap-2 font-mono text-sm text-muted-foreground">
                        {{ ticket.ticket_no }}
                        <TicketStatusBadge :status="ticket.status" />
                        <TicketPriorityBadge :priority="ticket.priority" />
                    </p>
                    <Heading :title="ticket.title" :description="ticket.customer ?? t('tickets.no_customer')" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('service.tickets.print', ticket.ulid)">
                            <Printer class="h-4 w-4" />
                            {{ t('ticket_print.button') }}
                        </Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <a :href="route('service.tickets.pdf', ticket.ulid)" target="_blank" rel="noopener">{{ t('ticket_print.pdf') }}</a>
                    </Button>
                    <Button v-if="can.update" variant="outline" as-child>
                        <Link :href="route('service.tickets.edit', ticket.ulid)">{{ t('common.edit') }}</Link>
                    </Button>
                </div>
            </div>

            <StepProgress :steps="stepBar.steps" :current="stepBar.current" :state="stepBar.state" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="move.errors.action ?? move.errors.comment" />

            <!-- Workflow -->
            <div v-if="actions.length || assignees || (can.checkWarranty && !ticket.warranty.status)" class="space-y-3 rounded-md border p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="action in actions"
                        :key="action"
                        size="sm"
                        :variant="actionClass(action)"
                        :disabled="move.processing"
                        @click="press(action)"
                    >
                        {{ t(`tickets.actions.${action}`) }}
                    </Button>

                    <form v-if="assignees" class="ml-auto flex items-center gap-2" @submit.prevent="saveAssignee">
                        <label for="assignee" class="text-sm text-muted-foreground">{{ t('tickets.assignee') }}</label>
                        <select id="assignee" v-model="assign.assignee_id" class="h-8 rounded-md border border-input bg-transparent px-2 text-sm">
                            <option :value="null">{{ t('tickets.unassigned') }}</option>
                            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <Button size="sm" variant="outline" :disabled="assign.processing || assign.assignee_id === ticket.assignee_id">
                            {{ t('tickets.assign') }}
                        </Button>
                    </form>
                </div>
                <InputError :message="assign.errors.assignee_id" />
                <p
                    v-if="can.checkWarranty && !ticket.warranty.status"
                    class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                >
                    {{ t('tickets.warranty_required') }}
                </p>

                <form v-if="pending" class="space-y-2" @submit.prevent="confirmPending">
                    <label for="reason" class="text-sm font-medium">{{ t('tickets.actions.' + pending) }} — {{ t('tickets.reason') }}</label>
                    <textarea
                        id="reason"
                        v-model="move.comment"
                        rows="2"
                        required
                        :placeholder="t('tickets.reason_placeholder')"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <div class="flex gap-2">
                        <Button size="sm" :disabled="move.processing">{{ t('tickets.confirm') }}</Button>
                        <Button size="sm" type="button" variant="ghost" @click="pending = null">{{ t('common.cancel') }}</Button>
                    </div>
                </form>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <section v-if="ticket.description" class="space-y-2">
                        <h3 class="text-sm font-semibold">{{ t('tickets.description_field') }}</h3>
                        <p class="whitespace-pre-line text-sm">{{ ticket.description }}</p>
                    </section>

                    <!-- The device being repaired -->
                    <section class="space-y-2">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            {{ t('tickets.device') }}
                            <span class="rounded bg-muted px-1.5 text-xs font-normal text-muted-foreground">
                                {{ t(ticket.asset ? 'tickets.device_registered_badge' : 'tickets.device_unregistered_badge') }}
                            </span>
                        </h3>
                        <dl class="grid gap-x-6 gap-y-2 rounded-md border p-4 text-sm sm:grid-cols-2">
                            <div v-for="fact in deviceFacts" :key="fact.label">
                                <dt class="text-xs text-muted-foreground">{{ t(fact.label) }}</dt>
                                <dd :class="{ 'font-mono': ['tickets.device_serial', 'tickets.device_ip'].includes(fact.label) }">
                                    {{ fact.value || t('common.none') }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <TicketIpPanel v-if="ip" :ticket-ulid="ticket.ulid" :ip="ip" :choices="ipChoices" />

                    <TicketReviewPanel v-if="review" :ticket-ulid="ticket.ulid" :review="review" />
                    <TicketPhotos :photos="photos" :signature="signature" />
                    <TicketTrackingLink v-if="tracking" :ticket-ulid="ticket.ulid" :url="tracking.url" :can-renew="tracking.can_renew" />
                    <TicketAppointment :ticket-ulid="ticket.ulid" :appointment-at="ticket.appointment_at" :can-change="can.update" />

                    <TicketForwardPanel v-if="forwards" :ticket-ulid="ticket.ulid" :forwards="forwards" />

                    <!-- Parts and assets asked of other companies for this job -->
                    <section v-if="sharedRequests.length || askOthersUrl" class="space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold">{{ t('shares.requests_title') }}</h3>
                            <Link v-if="askOthersUrl" :href="askOthersUrl" class="text-sm text-primary hover:underline">+ {{ t('shares.ask') }}</Link>
                        </div>
                        <SharedRequestList v-if="sharedRequests.length" :requests="sharedRequests" />
                    </section>

                    <!-- Repair report: printed on the job sheet for the customer to sign -->
                    <section v-if="ticket.report" class="space-y-2">
                        <div>
                            <h3 class="text-sm font-semibold">{{ t('tickets.report_title') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ t('tickets.report_hint') }}</p>
                        </div>
                        <form v-if="can.report" class="grid gap-4 rounded-md border p-4 sm:grid-cols-2" @submit.prevent="saveReport">
                            <div class="grid gap-1.5 sm:col-span-2">
                                <label for="cause" class="text-xs text-muted-foreground"
                                    >{{ t('tickets.cause') }} <span class="text-red-600" aria-hidden="true">*</span></label
                                >
                                <textarea
                                    id="cause"
                                    v-model="report.cause"
                                    rows="2"
                                    maxlength="5000"
                                    class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                />
                                <InputError :message="report.errors.cause" />
                            </div>
                            <div class="grid gap-1.5">
                                <label for="extra_cost" class="text-xs text-muted-foreground">{{ t('tickets.extra_cost') }}</label>
                                <input
                                    id="extra_cost"
                                    v-model="report.extra_cost"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                />
                                <InputError :message="report.errors.extra_cost" />
                            </div>
                            <div class="grid gap-1.5">
                                <label for="approver_name" class="text-xs text-muted-foreground"
                                    >{{ t('tickets.approver_name') }} <span class="text-red-600" aria-hidden="true">*</span></label
                                >
                                <input
                                    id="approver_name"
                                    v-model="report.approver_name"
                                    maxlength="255"
                                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                />
                                <InputError :message="report.errors.approver_name" />
                            </div>
                            <div class="sm:col-span-2">
                                <Button size="sm" :disabled="report.processing || !report.isDirty">{{ t('tickets.report_save') }}</Button>
                            </div>
                        </form>
                        <dl v-else class="grid gap-x-6 gap-y-2 rounded-md border p-4 text-sm sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <dt class="text-xs text-muted-foreground">{{ t('tickets.cause') }}</dt>
                                <dd class="whitespace-pre-line">{{ ticket.report.cause || t('common.none') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ t('tickets.extra_cost') }}</dt>
                                <dd>{{ ticket.report.extra_cost ?? t('common.none') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ t('tickets.approver_name') }}</dt>
                                <dd>{{ ticket.report.approver_name || t('common.none') }}</dd>
                            </div>
                        </dl>
                    </section>

                    <AttachmentList
                        :attachments="attachments"
                        :can-delete="can.deleteAttachments"
                        :upload-url="can.comment ? route('service.tickets.attachments.store', ticket.ulid) : null"
                    />

                    <section v-if="parts" class="space-y-3">
                        <h3 class="text-sm font-semibold">{{ t('ticket_parts.title') }}</h3>
                        <ul v-if="parts.items.length" class="divide-y rounded-md border text-sm">
                            <li v-for="part in parts.items" :key="part.part_id" class="flex flex-wrap items-center gap-2 px-3 py-2">
                                <span class="font-mono text-xs text-muted-foreground">{{ part.code }}</span>
                                <span>{{ part.name }}</span>
                                <StockMovementTypeBadge v-for="type in part.types" :key="type" :type="type" />
                                <span class="ml-auto whitespace-nowrap font-medium">{{ part.quantity }} {{ part.unit }}</span>
                                <button
                                    v-if="parts.canReturn"
                                    type="button"
                                    class="text-primary underline-offset-4 hover:underline"
                                    @click="returnPart(part)"
                                >
                                    {{ t('ticket_parts.give_back') }}
                                </button>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">{{ t('ticket_parts.none') }}</p>

                        <form v-if="parts.canIssue && parts.options.length" class="flex flex-wrap items-end gap-2" @submit.prevent="issuePart">
                            <div class="grid min-w-0 flex-1 gap-1">
                                <label for="part" class="text-xs text-muted-foreground">{{ t('ticket_parts.part') }}</label>
                                <select
                                    id="part"
                                    v-model="issue.part_id"
                                    required
                                    class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                                >
                                    <option :value="null" disabled>{{ t('ticket_parts.select') }}</option>
                                    <option v-for="option in parts.options" :key="option.id" :value="option.id">
                                        {{ option.code }} {{ option.name }} ({{
                                            t('ticket_parts.available', { qty: option.qty_on_hand, unit: option.unit })
                                        }})
                                    </option>
                                </select>
                            </div>
                            <div class="grid gap-1">
                                <label for="part_type" class="text-xs text-muted-foreground">{{ t('ticket_parts.type') }}</label>
                                <select id="part_type" v-model="issue.type" class="h-9 rounded-md border border-input bg-transparent px-2 text-sm">
                                    <option v-for="type in parts.types" :key="type" :value="type">{{ t(`stock_movements.types.${type}`) }}</option>
                                </select>
                            </div>
                            <div class="grid w-24 gap-1">
                                <label for="part_quantity" class="text-xs text-muted-foreground">{{ t('ticket_parts.quantity') }}</label>
                                <input
                                    id="part_quantity"
                                    v-model="issue.quantity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    required
                                    class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                                />
                            </div>
                            <Button size="sm" class="h-9" :disabled="issue.processing || issue.part_id === null">{{
                                t('ticket_parts.issue')
                            }}</Button>
                        </form>
                        <p v-else-if="parts.canIssue" class="text-sm text-muted-foreground">{{ t('ticket_parts.no_stock') }}</p>
                        <InputError
                            :message="
                                issue.errors.part_id ??
                                issue.errors.quantity ??
                                (page.props.errors as Record<string, string>).quantity ??
                                (page.props.errors as Record<string, string>).part_id
                            "
                        />
                    </section>

                    <section class="space-y-3">
                        <h3 class="text-sm font-semibold">{{ t('tickets.timeline') }}</h3>
                        <ol class="space-y-3 border-l pl-4">
                            <li v-for="event in events" :key="event.id" class="text-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ eventText(event) }}</span>
                                    <span
                                        v-if="event.is_internal"
                                        class="rounded bg-amber-100 px-1 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                                    >
                                        {{ t('tickets.internal_badge') }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ t('common.by', { name: event.user_name ?? t('common.system') }) }} · {{ dateTime(event.at) }}
                                    </span>
                                </div>
                                <p v-if="showBody(event)" class="mt-1 whitespace-pre-line rounded-md bg-muted/50 px-3 py-2">{{ event.body }}</p>
                            </li>
                        </ol>

                        <form v-if="can.comment" class="space-y-2" @submit.prevent="addComment">
                            <label for="comment" class="sr-only">{{ t('tickets.comment') }}</label>
                            <textarea
                                id="comment"
                                v-model="comment.body"
                                rows="3"
                                :placeholder="t('tickets.comment_placeholder')"
                                class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                            />
                            <InputError :message="comment.errors.body" />
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <label v-if="can.internalNotes" class="flex items-center gap-2 text-sm">
                                    <input v-model="comment.is_internal" type="checkbox" class="size-4 rounded border-input" />
                                    {{ t('tickets.internal') }}
                                </label>
                                <Button size="sm" :disabled="comment.processing || !comment.body">{{ t('tickets.add_comment') }}</Button>
                            </div>
                        </form>
                    </section>
                </div>

                <aside class="space-y-6">
                    <!-- Warranty: checked by staff before work starts -->
                    <section class="space-y-3 rounded-md border p-4 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold">{{ t('tickets.warranty') }}</h3>
                            <button
                                v-if="can.checkWarranty && ticket.warranty.status && !editingWarranty"
                                type="button"
                                class="text-xs text-primary underline-offset-4 hover:underline"
                                @click="editingWarranty = true"
                            >
                                {{ t('tickets.warranty_recheck') }}
                            </button>
                        </div>

                        <template v-if="ticket.warranty.status && !editingWarranty">
                            <p class="font-medium">
                                {{ t(`tickets.warranty_statuses.${ticket.warranty.status}`) }}
                                <span v-if="ticket.warranty.expires_on" class="font-normal text-muted-foreground">
                                    ({{ ticket.warranty.expires_on }})
                                </span>
                            </p>
                            <p v-if="ticket.warranty.checked_by" class="text-xs text-muted-foreground">
                                {{ t('tickets.warranty_checked_by', { name: ticket.warranty.checked_by, at: dateTime(ticket.warranty.checked_at) }) }}
                            </p>
                        </template>

                        <form v-else-if="can.checkWarranty" class="space-y-3" @submit.prevent="saveWarranty">
                            <p v-if="ticket.asset" class="text-xs text-muted-foreground">
                                {{ assetWarranty ? t('tickets.warranty_asset_date', { date: assetWarranty }) : t('tickets.warranty_asset_none') }}
                                <template v-if="suggestedWarranty">
                                    · {{ t('tickets.warranty_suggest', { status: t(`tickets.warranty_statuses.${suggestedWarranty}`) }) }}
                                </template>
                            </p>
                            <div class="flex flex-wrap gap-4">
                                <label v-for="status in ['in_warranty', 'out_of_warranty']" :key="status" class="flex items-center gap-2">
                                    <input v-model="warranty.warranty_status" type="radio" :value="status" class="size-4" />
                                    {{ t(`tickets.warranty_statuses.${status}`) }}
                                </label>
                            </div>
                            <div class="grid gap-1.5">
                                <label for="warranty_expires_on" class="text-xs text-muted-foreground">{{ t('tickets.warranty_expires_on') }}</label>
                                <input
                                    id="warranty_expires_on"
                                    v-model="warranty.warranty_expires_on"
                                    type="date"
                                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                />
                            </div>
                            <InputError :message="warranty.errors.warranty_status ?? warranty.errors.warranty_expires_on" />
                            <div class="flex gap-2">
                                <Button size="sm" :disabled="warranty.processing || !warranty.warranty_status">{{
                                    t('tickets.warranty_save')
                                }}</Button>
                                <Button v-if="ticket.warranty.status" size="sm" type="button" variant="ghost" @click="editingWarranty = false">
                                    {{ t('common.cancel') }}
                                </Button>
                            </div>
                        </form>

                        <p v-else class="text-muted-foreground">{{ t('tickets.warranty_unchecked') }}</p>
                    </section>

                    <section v-if="survey" class="space-y-3 rounded-md border p-4 text-sm">
                        <h3 class="font-semibold">{{ t('ticket_survey.title') }}</h3>

                        <template v-if="survey.answered">
                            <StarRating :model-value="survey.score" />
                            <p v-if="survey.comment" class="whitespace-pre-line">{{ survey.comment }}</p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="survey.answered_name"
                                    >{{ t('ticket_survey.answered_by', { name: survey.answered_name }) }} ·
                                </template>
                                {{ dateTime(survey.answered_at) }}
                            </p>
                        </template>

                        <form v-else-if="survey.canAnswer" class="space-y-3" @submit.prevent="submitRating">
                            <p>{{ t('ticket_survey.ask') }}</p>
                            <StarRating v-model="rating.score" size="lg" editable />
                            <textarea
                                v-model="rating.comment"
                                rows="2"
                                maxlength="2000"
                                :aria-label="t('surveys.comment')"
                                :placeholder="t('ticket_survey.comment_placeholder')"
                                class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                            />
                            <InputError :message="rating.errors.score ?? rating.errors.comment" />
                            <Button size="sm" :disabled="rating.processing || rating.score === null">{{ t('ticket_survey.submit') }}</Button>
                        </form>

                        <p v-else class="text-muted-foreground">{{ t('ticket_survey.pending') }}</p>

                        <!-- The score the customer ticked on the signed job sheet, keyed in by staff -->
                        <div v-if="survey.canPaper" class="space-y-3 border-t pt-3">
                            <Button v-if="!paperOpen" size="sm" variant="outline" type="button" @click="paperOpen = true">
                                {{ t('ticket_survey.paper_open') }}
                            </Button>
                            <form v-else class="space-y-3" @submit.prevent="submitPaper">
                                <p class="font-medium">{{ t('ticket_survey.paper_title') }}</p>
                                <p class="text-xs text-muted-foreground">{{ t('ticket_survey.paper_hint') }}</p>
                                <div class="space-y-1">
                                    <label v-for="score in [5, 4, 3, 2, 1]" :key="score" class="flex items-start gap-2">
                                        <input v-model="paper.score" type="radio" :value="score" class="mt-1 size-4" />
                                        <span>
                                            {{ t('ticket_survey.level_score', { score }) }} :
                                            <strong>{{ t(`ticket_survey.levels.${score}.label`) }}</strong>
                                        </span>
                                    </label>
                                </div>
                                <div class="grid gap-1.5">
                                    <label for="rater_name" class="text-xs text-muted-foreground">{{ t('ticket_survey.rater_name') }}</label>
                                    <input
                                        id="rater_name"
                                        v-model="paper.name"
                                        required
                                        maxlength="255"
                                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                    />
                                </div>
                                <textarea
                                    v-model="paper.comment"
                                    rows="2"
                                    maxlength="2000"
                                    :aria-label="t('surveys.comment')"
                                    :placeholder="t('ticket_survey.comment_placeholder')"
                                    class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                />
                                <InputError :message="paper.errors.score ?? paper.errors.name ?? paper.errors.comment" />
                                <div class="flex gap-2">
                                    <Button size="sm" :disabled="paper.processing || paper.score === null">{{
                                        t('ticket_survey.paper_submit')
                                    }}</Button>
                                    <Button size="sm" type="button" variant="ghost" @click="paperOpen = false">{{ t('common.cancel') }}</Button>
                                </div>
                            </form>
                        </div>

                        <div v-if="survey.url" class="space-y-2 border-t pt-3">
                            <p class="text-xs text-muted-foreground">{{ t('ticket_survey.share_hint') }}</p>
                            <!-- eslint-disable-next-line vue/no-v-html -- SVG generated on the server from the link -->
                            <div v-if="survey.qr" class="mx-auto size-32 [&>svg]:size-full" v-html="survey.qr" />
                            <p class="break-all font-mono text-xs">{{ survey.url }}</p>
                            <Button size="sm" variant="outline" type="button" @click="copyLink">
                                {{ copied ? t('ticket_survey.copied') : t('ticket_survey.copy') }}
                            </Button>
                        </div>
                    </section>
                    <section class="space-y-2 rounded-md border p-4 text-sm">
                        <h3 class="font-semibold">{{ t('tickets.sla') }}</h3>
                        <template v-if="ticket.contract">
                            <p class="text-xs text-muted-foreground">{{ t('tickets.service_window', { window: ticket.service_window ?? '' }) }}</p>
                            <div>
                                <SlaBadge :state="ticket.sla.response" :label="`${t('tickets.sla_response')} ${hours(ticket.response_minutes)}`" />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        ticket.responded_at
                                            ? t('tickets.done_at', { at: dateTime(ticket.responded_at) })
                                            : ticket.response_due_at
                                              ? t('tickets.due', { at: dateTime(ticket.response_due_at) })
                                              : ''
                                    }}
                                </p>
                            </div>
                            <div>
                                <SlaBadge :state="ticket.sla.resolve" :label="`${t('tickets.sla_resolve')} ${hours(ticket.resolve_minutes)}`" />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        ticket.resolved_at
                                            ? t('tickets.done_at', { at: dateTime(ticket.resolved_at) })
                                            : ticket.resolve_due_at
                                              ? t('tickets.due', { at: dateTime(ticket.resolve_due_at) })
                                              : ''
                                    }}
                                </p>
                            </div>
                            <p v-if="ticket.hold_minutes > 0" class="text-xs text-muted-foreground">
                                {{ t('tickets.hold_minutes', { minutes: ticket.hold_minutes }) }}
                            </p>
                        </template>
                        <p v-else class="text-muted-foreground">{{ t('tickets.out_of_contract_hint') }}</p>
                    </section>

                    <dl class="space-y-3 rounded-md border p-4 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('tickets.asset') }}</dt>
                            <dd>
                                <template v-if="ticket.asset">
                                    <Link
                                        v-if="ticket.asset.can_view"
                                        :href="route('asset.assets.show', ticket.asset.ulid)"
                                        class="text-primary underline-offset-4 hover:underline"
                                    >
                                        {{ ticket.asset.asset_code }}
                                    </Link>
                                    <span v-else>{{ ticket.asset.asset_code }}</span>
                                    {{ ticket.asset.name }}
                                </template>
                                <template v-else>{{ t('tickets.no_asset') }}</template>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('tickets.contract') }}</dt>
                            <dd>
                                <template v-if="ticket.contract">
                                    <Link
                                        v-if="ticket.contract.can_view"
                                        :href="route('contract.contracts.show', ticket.contract.id)"
                                        class="text-primary underline-offset-4 hover:underline"
                                    >
                                        {{ ticket.contract.contract_no }}
                                    </Link>
                                    <span v-else>{{ ticket.contract.contract_no }}</span>
                                </template>
                                <span v-else class="rounded bg-muted px-1 text-xs">{{ t('tickets.out_of_contract') }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('tickets.assignee') }}</dt>
                            <dd>{{ ticket.assignee ?? t('tickets.unassigned') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('tickets.contact_name') }}</dt>
                            <dd>
                                {{ ticket.contact_name ?? t('common.none') }}
                                <span v-if="ticket.contact_phone" class="text-muted-foreground"> · {{ ticket.contact_phone }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('tickets.source') }}</dt>
                            <dd>{{ t(`tickets.sources.${ticket.source}`) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('tickets.branch') }}</dt>
                            <dd>{{ ticket.branch ?? t('common.none') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('tickets.reporter') }}</dt>
                            <dd>{{ ticket.reporter ?? t('common.none') }} · {{ dateTime(ticket.created_at) }}</dd>
                        </div>
                    </dl>
                </aside>
            </div>

            <Button variant="outline" as-child>
                <Link :href="route('service.tickets.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
