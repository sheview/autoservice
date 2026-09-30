<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SlaBadge from '@/components/SlaBadge.vue';
import StarRating from '@/components/StarRating.vue';
import StockMovementTypeBadge from '@/components/StockMovementTypeBadge.vue';
import TicketPriorityBadge from '@/components/TicketPriorityBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import { ref } from 'vue';

interface TicketDetail {
    ulid: string;
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
    can: { update: boolean; comment: boolean; internalNotes: boolean };
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

// --- satisfaction survey (Survey module) ---------------------------------------
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
        default:
            return t('tickets.events.comment');
    }
};

const showBody = (event: TicketEvent) => event.body && ['comment', 'status'].includes(event.type);
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
                    <Button v-if="can.update" variant="outline" as-child>
                        <Link :href="route('service.tickets.edit', ticket.ulid)">{{ t('common.edit') }}</Link>
                    </Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="move.errors.action ?? move.errors.comment" />

            <!-- Workflow -->
            <div v-if="actions.length || assignees" class="space-y-3 rounded-md border p-4">
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
