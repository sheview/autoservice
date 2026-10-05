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
        planned_start: string;
        planned_end: string;
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
        approvals: { step: number; side: string; decision: string; note: string | null; actor_name: string | null; round: number; decided_at: string }[];
        waiting_for: { step: number; side: string; name: string | null } | null;
    };
    attachments: Attachment[];
    can: { edit: boolean; cancel: boolean; viewIds: boolean; copy: boolean; decide: boolean; own: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('room_requests.title'), href: route('room-access.requests.index') },
    { title: props.request.request_no, href: route('room-access.requests.show', props.request.ulid) },
];
const errors = computed(() => page.props.errors as Record<string, string>);

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

        <div class="max-w-5xl space-y-6 p-4">
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

            <form v-if="cancelling" class="flex flex-wrap items-end gap-2 rounded-md border p-3" @submit.prevent="cancel">
                <Input v-model="cancelForm.reason" :placeholder="t('room_requests.cancel_reason')" maxlength="1000" class="max-w-md" />
                <Button variant="destructive" :disabled="cancelForm.processing">{{ t('room_requests.cancel_request') }}</Button>
            </form>

            <dl class="grid gap-x-6 gap-y-3 rounded-md border p-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('room_requests.when') }}</dt>
                    <dd>{{ dateTime(request.planned_start) }} – {{ dateTime(request.planned_end) }}</dd>
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
