<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import RoomRequestStatusBadge from '@/components/RoomRequestStatusBadge.vue';
import RoomRulesDialog from '@/components/RoomRulesDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface Acceptance {
    context: string;
    version: number | null;
    accepted_by_name: string;
    on_behalf_of_team: boolean;
    snapshot: { customer: string; room: string; summary: string[]; company_terms: string[]; missing: boolean };
    ip: string | null;
    accepted_at: string;
}

const props = defineProps<{
    request: {
        ulid: string;
        request_no: string;
        status: string;
        requester_name: string | null;
        purpose: string;
        decision_note: string | null;
        work_summary: string | null;
        planned_start: string;
        planned_end: string;
        schedule: string | null;
        visits: { id: number; entered_at: string; exited_at: string | null; entered_by_name: string | null; exited_by_name: string | null }[];
        submitted_at: string | null;
        room: { ulid: string | null; name: string | null; location: string | null; requires_id_number: boolean };
        customer: string;
        ticket: { ulid: string; ticket_no: string; title: string } | null;
        contract: { contract_no: string; title: string } | null;
        people: { id: number; name: string; company: string | null; phone: string | null; id_number: string | null }[];
        items: { name: string; serial_number: string | null; quantity: number; direction: string }[];
        acceptances: Acceptance[];
        events: { id: number; action: string; from_status: string | null; to_status: string; actor_name: string | null; note: string | null; at: string }[];
        id_numbers_purged: boolean;
        approved_at: string | null;
        entered_at: string | null;
        entered_by_name: string | null;
        exited_at: string | null;
        exited_by_name: string | null;
        items_confirmed_at: string | null;
        items_confirmed_by_name: string | null;
        overstaying: boolean;
        accept_on_enter: boolean;
        approvals: { step: number; side: string; decision: string; note: string | null; actor_name: string | null; round: number; decided_at: string }[];
        waiting_for: { step: number; side: string; name: string | null } | null;
    };
    attachments: Attachment[];
    clashes: {
        requests: { ulid: string | null; request_no: string | null; requester_name: string | null; status: string; planned_start: string; planned_end: string; schedule: string | null }[];
        freezes: { from: string; to: string; reason: string | null }[];
        holidays: { date: string; name: string }[];
    } | null;
    permit: {
        link: string | null;
        qr: string | null;
        valid: boolean;
        expires_at: string | null;
        can_renew: boolean;
        guard_link: string | null;
        guard_enabled: boolean;
    } | null;
    can: { edit: boolean; cancel: boolean; viewIds: boolean; copy: boolean; decide: boolean; own: boolean; record: boolean; finish: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('room_requests.title'), href: route('room-access.requests.index') },
    { title: props.request.request_no, href: route('room-access.requests.show', props.request.ulid) },
];
const errors = computed(() => page.props.errors as Record<string, string>);
const hasClashes = computed(() => !!props.clashes && props.clashes.requests.length + props.clashes.freezes.length + props.clashes.holidays.length > 0);

// Sending a draft: the rules popup first.
const dialog = ref(false);
const sending = ref(false);
const submit = (versionId: number | null) => {
    sending.value = true;
    router.post(route('room-access.requests.submit', props.request.ulid), { accept: true, version_id: versionId }, {
        preserveScroll: true,
        onFinish: () => ((sending.value = false), (dialog.value = false)),
    });
};

const cancelForm = useForm({ reason: '' });
const cancelling = ref(false);
const cancel = () => cancelForm.post(route('room-access.requests.cancel', props.request.ulid), { preserveScroll: true, onSuccess: () => (cancelling.value = false) });

// Full ID numbers, one at a time, for whoever may see them (logged on the server).
const revealed = reactive<Record<number, string>>({});
const reveal = async (personId: number) => {
    const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
    const response = await fetch(route('room-access.requests.reveal-id', [props.request.ulid, personId]), {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (response.ok) revealed[personId] = (await response.json()).id_number ?? '';
};

const shownText = ref<number | null>(null);

const copied = ref(false);
const copyLink = async () => {
    if (props.permit?.link) {
        await navigator.clipboard.writeText(props.permit.link);
        copied.value = true;
    }
};
// The visit: going in (accepting the rules again when the room asks), out, and the summary afterwards.
const enterAccept = ref(false);
const enter = () => router.post(route('room-access.requests.enter', props.request.ulid), { accept: enterAccept.value }, { preserveScroll: true });
const exit = () => router.post(route('room-access.requests.exit', props.request.ulid), {}, { preserveScroll: true });
const finishForm = useForm({ work_summary: '', items_confirmed: false });
const finish = () => finishForm.post(route('room-access.requests.finish', props.request.ulid), { preserveScroll: true });
const guardCopied = ref(false);
const copyGuard = async () => {
    if (props.permit?.guard_link) {
        await navigator.clipboard.writeText(props.permit.guard_link);
        guardCopied.value = true;
    }
};
const renewGuard = () => {
    if (confirm(t('room_permit.renew_confirm'))) router.post(route('room-access.requests.guard-link', props.request.ulid), {}, { preserveScroll: true });
};

const renew = () => {
    if (confirm(t('room_permit.renew_confirm'))) router.post(route('room-access.requests.permit.renew', props.request.ulid), {}, { preserveScroll: true });
};

// Deciding the step that waits for this user: approve at once; turn down / ask with a message.
const decision = useForm({ decision: '', note: '' });
const choice = ref<'reject' | 'ask' | null>(null);
const decide = (value: 'approve' | 'reject' | 'ask') => {
    decision.decision = value;
    decision.post(route('room-access.requests.decide', props.request.ulid), {
        preserveScroll: true,
        onSuccess: () => {
            choice.value = null;
            decision.reset();
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="request.request_no" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-sm text-muted-foreground">{{ request.request_no }}</span>
                        <RoomRequestStatusBadge :status="request.status" />
                    </div>
                    <Heading :title="`${request.customer} · ${request.room.name}`" :description="request.room.location ?? ''" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <template v-if="can.edit">
                        <Button @click="dialog = true">{{ t('room_requests.submit') }}</Button>
                        <Button variant="outline" as-child>
                            <Link :href="route('room-access.requests.edit', request.ulid)">{{ t('common.edit') }}</Link>
                        </Button>
                    </template>
                    <Button v-if="can.copy" variant="outline" as-child>
                        <Link :href="route('room-access.requests.create', { copy: request.ulid })">{{ t('room_requests.copy') }}</Link>
                    </Button>
                    <Button v-if="can.cancel" variant="outline" class="text-red-600" @click="cancelling = !cancelling">{{ t('room_requests.cancel_request') }}</Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.accept || errors.rules || errors.people || errors.planned_start || errors.planned_end || errors.request || errors.server_room_id" />
            <p v-if="request.decision_note" class="rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                {{ t('room_requests.decision_note') }}: {{ request.decision_note }}
            </p>

            <div
                v-if="hasClashes && clashes"
                class="space-y-1 rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                <p class="font-semibold">{{ t('room_requests.clash_title') }}</p>
                <p v-for="(f, i) in clashes.freezes" :key="'f' + i" class="text-red-700 dark:text-red-300">
                    {{ t('room_requests.clash_freeze', { from: dateTime(f.from), to: dateTime(f.to), reason: f.reason ?? '-' }) }}
                </p>
                <p v-for="(r, i) in clashes.requests" :key="'r' + i">
                    <template v-if="r.request_no">
                        <Link v-if="r.ulid" :href="route('room-access.requests.show', r.ulid)" class="underline">{{
                            t('room_requests.clash_request', {
                                no: r.request_no,
                                who: r.requester_name ?? '-',
                                status: t(`room_requests.statuses.${r.status}`),
                                when: `${dateTime(r.planned_start)} – ${dateTime(r.planned_end)}` + (r.schedule ? ` (${r.schedule})` : ''),
                            })
                        }}</Link>
                    </template>
                    <template v-else>{{
                        t('room_requests.clash_hidden', {
                            when: `${dateTime(r.planned_start)} – ${dateTime(r.planned_end)}` + (r.schedule ? ` (${r.schedule})` : ''),
                            status: t(`room_requests.statuses.${r.status}`),
                        })
                    }}</template>
                </p>
                <p v-for="h in clashes.holidays" :key="h.date">{{ t('room_requests.clash_holiday', { date: h.date, name: h.name }) }}</p>
            </div>

            <section v-if="request.status === 'pending' || request.approvals.length" class="space-y-3 rounded-md border p-4">
                <h3 class="text-sm font-semibold">{{ t('room_requests.approval_title') }}</h3>
                <p v-if="request.waiting_for" class="text-sm">
                    {{ t('room_requests.waiting_for', { step: request.waiting_for.step, who: request.waiting_for.name ?? t('room_requests.waiting_anyone') }) }}
                </p>
                <p v-if="request.approved_at" class="text-sm text-green-700">{{ t('room_requests.approved_at', { at: dateTime(request.approved_at) }) }}</p>
                <ul v-if="request.approvals.length" class="space-y-1 text-sm">
                    <li v-for="(a, i) in request.approvals" :key="i">
                        {{ t('room_requests.decision_line', { step: a.step, decision: t(`room_requests.decisions.${a.decision}`), name: a.actor_name ?? '-', at: dateTime(a.decided_at) }) }}
                        <span v-if="a.note" class="block text-xs text-muted-foreground">{{ a.note }}</span>
                    </li>
                </ul>
                <template v-if="can.decide">
                    <div class="flex flex-wrap gap-2">
                        <Button :disabled="decision.processing" @click="decide('approve')">{{ t('room_requests.approve') }}</Button>
                        <Button variant="outline" @click="choice = 'ask'">{{ t('room_requests.ask') }}</Button>
                        <Button variant="outline" class="text-red-600" @click="choice = 'reject'">{{ t('room_requests.reject') }}</Button>
                    </div>
                    <form v-if="choice" class="space-y-2" @submit.prevent="decide(choice)">
                        <label class="block text-sm font-medium">{{ choice === 'ask' ? t('room_requests.ask_message') : t('room_requests.reject_reason') }}</label>
                        <textarea v-model="decision.note" rows="3" required maxlength="2000" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm" />
                        <InputError :message="decision.errors.note" />
                        <div class="flex gap-2">
                            <Button :variant="choice === 'reject' ? 'destructive' : 'default'" :disabled="decision.processing">{{
                                choice === 'ask' ? t('room_requests.send_ask') : t('room_requests.send_reject')
                            }}</Button>
                            <Button type="button" variant="ghost" @click="choice = null">{{ t('common.cancel') }}</Button>
                        </div>
                    </form>
                </template>
                <p v-else-if="request.status === 'pending' && can.own" class="text-xs text-muted-foreground">
                    {{ t('room_requests.own_request_note') }}
                </p>
            </section>

            <section v-if="permit" class="flex flex-wrap items-start gap-4 rounded-md border-2 border-green-300 bg-green-50/50 p-4 dark:border-green-800 dark:bg-green-950/30">
                <div v-if="permit.qr" class="size-36 shrink-0 rounded bg-white p-1" v-html="permit.qr" />
                <div class="min-w-0 flex-1 space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('room_permit.section') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ t('room_permit.hint') }}</p>
                    <p v-if="permit.link" class="break-all font-mono text-xs">{{ permit.link }}</p>
                    <p v-if="permit.expires_at" class="text-xs text-muted-foreground">{{ t('room_permit.expires', { at: dateTime(permit.expires_at) }) }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="permit.link" size="sm" variant="outline" @click="copyLink">{{ copied ? t('room_permit.copied') : t('room_permit.copy') }}</Button>
                        <Button size="sm" variant="outline" as-child>
                            <a :href="route('room-access.requests.permit.pdf', request.ulid)">{{ t('room_permit.pdf') }}</a>
                        </Button>
                        <Button size="sm" variant="outline" as-child>
                            <a :href="route('room-access.requests.permit.print', request.ulid)" target="_blank">{{ t('room_permit.print') }}</a>
                        </Button>
                        <Button v-if="permit.can_renew" size="sm" variant="ghost" @click="renew">{{ t('room_permit.renew') }}</Button>
                    </div>
                </div>
            </section>

            <section v-if="['approved', 'inside', 'exited'].includes(request.status)" class="space-y-3 rounded-md border p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-semibold">{{ t('room_requests.visit_title') }}</h3>
                    <span v-if="request.overstaying" class="rounded bg-red-100 px-1.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-200">{{
                        t('room_requests.overstaying')
                    }}</span>
                </div>
                <template v-if="!request.schedule">
                    <p v-if="request.entered_at" class="text-sm">{{ t('room_requests.entered_at', { at: dateTime(request.entered_at), by: request.entered_by_name ?? '-' }) }}</p>
                    <p v-if="request.exited_at" class="text-sm">{{ t('room_requests.exited_at', { at: dateTime(request.exited_at), by: request.exited_by_name ?? '-' }) }}</p>
                </template>
                <!-- A standing request: each day it was used. -->
                <template v-else>
                    <p class="text-sm">{{ t('room_requests.schedule') }}: {{ t('room_requests.schedule_every', { schedule: request.schedule }) }}</p>
                    <p v-if="!request.visits.length" class="text-sm text-muted-foreground">{{ t('room_requests.visits_none') }}</p>
                    <ul v-else class="max-h-64 space-y-1 overflow-y-auto text-sm">
                        <li v-for="v in [...request.visits].reverse()" :key="v.id">
                            {{ t('room_requests.visit_line', { in: dateTime(v.entered_at), out: v.exited_at ? dateTime(v.exited_at) : t('room_requests.still_inside') }) }}
                            <span class="text-xs text-muted-foreground">· {{ v.entered_by_name }}<template v-if="v.exited_by_name"> / {{ v.exited_by_name }}</template></span>
                        </li>
                    </ul>
                </template>
                <InputError :message="errors.visit || errors.accept" />
                <div v-if="can.record && request.status === 'approved'" class="space-y-2">
                    <label v-if="request.accept_on_enter" class="flex items-center gap-2 text-sm">
                        <input v-model="enterAccept" type="checkbox" class="size-4 rounded border-input" />{{ t('room_requests.enter_accept') }}
                    </label>
                    <Button @click="enter">{{ t('room_requests.enter') }}</Button>
                </div>
                <Button v-if="can.record && request.status === 'inside'" @click="exit">{{ t('room_requests.exit') }}</Button>

                <template v-if="request.status === 'exited'">
                    <div v-if="request.work_summary" class="space-y-1 rounded-md bg-muted/40 p-3 text-sm">
                        <div class="text-xs text-muted-foreground">{{ t('room_requests.work_summary') }}</div>
                        <p class="whitespace-pre-line">{{ request.work_summary }}</p>
                        <p v-if="request.items_confirmed_at" class="text-xs text-muted-foreground">
                            {{ t('room_requests.items_confirmed_by', { name: request.items_confirmed_by_name ?? '-', at: dateTime(request.items_confirmed_at) }) }}
                        </p>
                    </div>
                    <form v-else-if="can.finish" class="space-y-2" @submit.prevent="finish">
                        <h4 class="text-sm font-medium">{{ t('room_requests.finish_title') }}</h4>
                        <textarea
                            v-model="finishForm.work_summary"
                            rows="4"
                            required
                            maxlength="5000"
                            :placeholder="t('room_requests.work_summary')"
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="finishForm.errors.work_summary" />
                        <label v-if="request.items.length" class="flex items-center gap-2 text-sm">
                            <input v-model="finishForm.items_confirmed" type="checkbox" class="size-4 rounded border-input" />{{ t('room_requests.items_confirmed') }}
                        </label>
                        <InputError :message="(finishForm.errors as Record<string, string>).items_confirmed" />
                        <Button :disabled="finishForm.processing">{{ t('room_requests.finish') }}</Button>
                    </form>
                </template>
            </section>

            <section v-if="permit?.guard_link" class="space-y-2 rounded-md border p-4">
                <h3 class="text-sm font-semibold">{{ t('room_requests.guard_link_title') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('room_requests.guard_link_hint') }}</p>
                <p class="break-all font-mono text-xs">{{ permit.guard_link }}</p>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" @click="copyGuard">{{ guardCopied ? t('room_permit.copied') : t('room_permit.copy') }}</Button>
                    <Button size="sm" variant="ghost" @click="renewGuard">{{ t('room_requests.guard_link_renew') }}</Button>
                </div>
            </section>

            <form v-if="cancelling" class="flex flex-wrap items-end gap-2 rounded-md border p-3" @submit.prevent="cancel">
                <Input v-model="cancelForm.reason" :placeholder="t('room_requests.cancel_reason')" maxlength="1000" class="max-w-md" />
                <Button variant="destructive" :disabled="cancelForm.processing">{{ t('room_requests.cancel_request') }}</Button>
            </form>

            <dl class="grid gap-x-6 gap-y-3 rounded-md border p-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('room_requests.when') }}</dt>
                    <dd>
                        {{ dateTime(request.planned_start) }} – {{ dateTime(request.planned_end) }}
                        <span v-if="request.schedule" class="block font-medium">{{ t('room_requests.schedule_every', { schedule: request.schedule }) }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('room_requests.requester') }}</dt>
                    <dd>{{ request.requester_name }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">{{ t('room_requests.purpose') }}</dt>
                    <dd class="whitespace-pre-line">{{ request.purpose }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('room_requests.ticket') }}</dt>
                    <dd>
                        <Link v-if="request.ticket" :href="route('service.tickets.show', request.ticket.ulid)" class="text-primary hover:underline">
                            {{ request.ticket.ticket_no }} {{ request.ticket.title }}
                        </Link>
                        <template v-else>{{ t('room_requests.no_ticket') }}</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('room_requests.contract') }}</dt>
                    <dd>{{ request.contract ? `${request.contract.contract_no} ${request.contract.title}` : t('room_requests.no_contract') }}</dd>
                </div>
            </dl>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('room_requests.people') }}</h3>
                <p v-if="request.id_numbers_purged" class="text-xs text-muted-foreground">{{ t('room_requests.ids_purged') }}</p>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="person in request.people" :key="person.id" class="border-t first:border-t-0">
                                <td class="px-4 py-2 font-medium">{{ person.name }}</td>
                                <td class="px-4 py-2">{{ person.company }}</td>
                                <td class="px-4 py-2">{{ person.phone }}</td>
                                <td v-if="request.room.requires_id_number" class="whitespace-nowrap px-4 py-2 font-mono">
                                    {{ revealed[person.id] ?? person.id_number ?? '-' }}
                                    <button
                                        v-if="can.viewIds && person.id_number && !revealed[person.id]"
                                        type="button"
                                        class="ml-2 font-sans text-xs text-primary hover:underline"
                                        @click="reveal(person.id)"
                                    >
                                        {{ t('room_requests.reveal_id') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('room_requests.items') }}</h3>
                <p v-if="!request.items.length" class="text-sm text-muted-foreground">{{ t('room_requests.no_items') }}</p>
                <ul v-else class="divide-y rounded-md border text-sm">
                    <li v-for="(item, i) in request.items" :key="i" class="flex flex-wrap gap-x-3 px-4 py-2">
                        <span class="font-medium">{{ item.name }}</span>
                        <span v-if="item.serial_number" class="font-mono text-xs">SN {{ item.serial_number }}</span>
                        <span>× {{ item.quantity }}</span>
                        <span class="text-xs text-muted-foreground">{{ t(`room_requests.directions.${item.direction}`) }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="request.acceptances.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('room_requests.acceptance_title') }}</h3>
                <div v-for="(a, i) in request.acceptances" :key="i" class="space-y-1 rounded-md border p-3 text-sm">
                    <p>
                        {{
                            a.version
                                ? t('room_requests.acceptance_line', { customer: a.snapshot.customer, room: a.snapshot.room, version: a.version, at: dateTime(a.accepted_at), name: a.accepted_by_name })
                                : t('room_requests.acceptance_line_none', { at: dateTime(a.accepted_at), name: a.accepted_by_name })
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="a.on_behalf_of_team">{{ t('room_requests.acceptance_team') }} · </template>
                        <template v-if="a.ip">{{ t('room_requests.acceptance_ip', { ip: a.ip }) }} · </template>
                        <button type="button" class="text-primary hover:underline" @click="shownText = shownText === i ? null : i">{{ t('room_requests.show_text') }}</button>
                    </p>
                    <div v-if="shownText === i" class="space-y-2 rounded-md bg-muted/40 p-3">
                        <ol v-if="a.snapshot.summary.length" class="list-decimal pl-5">
                            <li v-for="(line, j) in a.snapshot.summary" :key="j">{{ line }}</li>
                        </ol>
                        <ol class="list-decimal pl-5 text-muted-foreground">
                            <li v-for="(line, j) in a.snapshot.company_terms" :key="j">{{ line }}</li>
                        </ol>
                    </div>
                </div>
            </section>

            <AttachmentList
                :attachments="attachments"
                :can-delete="can.edit"
                :upload-url="can.edit ? route('room-access.requests.attachments.store', request.ulid) : null"
                images
            />

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('room_requests.history') }}</h3>
                <ol class="space-y-1 border-l-2 pl-4 text-sm">
                    <li v-for="e in request.events" :key="e.id">
                        <span class="font-medium">{{ t(`room_requests.actions.${e.action}`) }}</span>
                        <span class="text-muted-foreground"> · {{ dateTime(e.at) }} · {{ e.actor_name ?? t('common.system') }}</span>
                        <span v-if="e.note" class="block text-xs">{{ e.note }}</span>
                    </li>
                </ol>
            </section>
        </div>

        <RoomRulesDialog :room-ulid="request.room.ulid" :open="dialog" :processing="sending" @accept="submit" @close="dialog = false" />
    </AppLayout>
</template>
