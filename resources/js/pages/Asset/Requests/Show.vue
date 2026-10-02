<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PurchaseStatusBadge from '@/components/PurchaseStatusBadge.vue';
import RequestStatusBadge from '@/components/RequestStatusBadge.vue';
import RequestSteps from '@/components/RequestSteps.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { CheckoutLineRow, CheckoutRequestRow } from '@/types/checkout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * One issue/loan request: where it stands, its lines, and what the user may do now — approve
 * (lowering or rejecting lines with a reason), hand out line by line, backorder (and order),
 * give up what is left, take lent assets back.
 */
const props = defineProps<{
    request: CheckoutRequestRow;
    can: {
        edit: boolean;
        submit: boolean;
        cancel: boolean;
        approve: boolean;
        fulfill: boolean;
        order: boolean;
        return: boolean;
        close: boolean;
        print: boolean;
    };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('requests.title'), href: route('asset.requests.index') },
    { title: props.request.request_no, href: route('asset.requests.show', props.request.ulid) },
];
const errors = computed(() => page.props.errors as Record<string, string>);
const options = { preserveScroll: true };

// --- Whole request --------------------------------------------------------------------------
const post = (name: string, confirmText?: string) => {
    if (confirmText && !confirm(confirmText)) return;
    router.post(route(`asset.requests.${name}`, props.request.ulid), {}, options);
};

// Approval: every line as asked unless lowered or rejected (with a reason).
const decisions = reactive(
    Object.fromEntries(props.request.items.map((item) => [item.id, { qty: item.qty_requested, reject: false, reason: '' }])) as Record<
        number,
        { qty: number; reject: boolean; reason: string }
    >,
);
const approveForm = useForm({ lines: {} as Record<number, { qty: number | null; reject_reason: string | null }> });
const approve = () =>
    approveForm
        .transform(() => ({
            lines: Object.fromEntries(
                Object.entries(decisions).map(([id, d]) => [
                    id,
                    d.reject ? { qty: 0, reject_reason: d.reason } : { qty: d.qty, reject_reason: null },
                ]),
            ),
        }))
        .post(route('asset.requests.approve', props.request.ulid), options);

const rejecting = ref(false);
const rejectForm = useForm({ reject_reason: '' });
const rejectAll = () =>
    rejectForm.post(route('asset.requests.reject', props.request.ulid), { ...options, onSuccess: () => (rejecting.value = false) });

// --- Lines ----------------------------------------------------------------------------------
type Mode = 'fulfill' | 'cancel' | 'return';
const open = reactive<{ id: number | null; mode: Mode | null }>({ id: null, mode: null });
const lineForm = useForm({ qty: 1, reason: '', condition: '' });

const toFulfill = (line: CheckoutLineRow) => ['approved', 'partial', 'backordered'].includes(line.status) && line.remaining > 0;
const canReturn = (line: CheckoutLineRow) => line.item_type === 'asset' && line.outstanding > 0;

const openAction = (line: CheckoutLineRow, mode: Mode) => {
    lineForm.clearErrors();
    lineForm.reason = '';
    lineForm.condition = '';
    lineForm.qty =
        mode === 'return' ? line.outstanding : line.on_hand !== null ? Math.max(1, Math.min(line.remaining, line.on_hand)) : line.remaining;
    open.id = line.id;
    open.mode = mode;
};
const closeAction = () => {
    open.id = null;
    open.mode = null;
};

const sendLine = (line: CheckoutLineRow) => {
    const done = { ...options, onSuccess: closeAction };
    if (open.mode === 'fulfill') {
        lineForm.transform((d) => ({ qty: d.qty })).post(route('asset.items.fulfill', line.id), done);
    } else if (open.mode === 'cancel') {
        lineForm.transform((d) => ({ reason: d.reason })).post(route('asset.items.cancel', line.id), done);
    } else if (open.mode === 'return') {
        lineForm.transform((d) => ({ qty: d.qty, condition: d.condition || null })).post(route('asset.items.return', line.id), done);
    }
};

const backorder = (line: CheckoutLineRow, order: boolean) => {
    if (!confirm(t(order ? 'requests.confirm_order' : 'requests.confirm_backorder', { item: line.item_name, qty: line.remaining }))) return;
    router.post(route('asset.items.backorder', line.id), order ? { order: 1 } : {}, options);
};

const fulfillments = computed(() =>
    props.request.items
        .flatMap((line) => (line.fulfillments ?? []).map((f) => ({ ...f, line })))
        .sort((a, b) => (a.at ?? '').localeCompare(b.at ?? '')),
);
const lineError = (field: string) => (lineForm.errors as Record<string, string>)[field];
const textareaClass = 'shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="request.request_no" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-1">
                    <Heading :title="t('requests.show_title', { no: request.request_no })" />
                    <RequestStatusBadge :status="request.status" />
                    <span v-if="request.auto_approved" class="ml-2 text-xs text-muted-foreground">{{ t('requests.auto_approved') }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.edit" variant="outline" as-child>
                        <Link :href="route('asset.requests.edit', request.ulid)">{{ t('common.edit') }}</Link>
                    </Button>
                    <Button v-if="can.submit" @click="post('submit')">{{ t('requests.send') }}</Button>
                    <Button v-if="can.close" variant="outline" @click="post('close', t('requests.confirm_close', { no: request.request_no }))">
                        {{ t('requests.close') }}
                    </Button>
                    <Button v-if="can.cancel" variant="outline" @click="post('cancel', t('requests.confirm_cancel', { no: request.request_no }))">
                        {{ t('requests.cancel') }}
                    </Button>
                    <template v-if="can.print">
                        <Button variant="outline" as-child>
                            <a :href="route('asset.requests.print', request.ulid)" target="_blank" rel="noopener">{{ t('requests.print') }}</a>
                        </Button>
                        <Button variant="outline" as-child>
                            <a :href="route('asset.requests.pdf', request.ulid)">{{ t('requests.pdf') }}</a>
                        </Button>
                    </template>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p v-if="page.props.flash.error" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </p>
            <InputError :message="errors.request" />
            <InputError :message="errors.item" />

            <RequestSteps :request="request" />

            <p
                v-if="request.reject_reason && (request.status === 'rejected' || request.status === 'cancelled')"
                class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
            >
                {{ t('requests.reject_reason') }}: {{ request.reject_reason }}
            </p>

            <!-- Header -->
            <dl class="grid gap-x-6 gap-y-3 rounded-md border p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('requests.borrower') }}</dt>
                    <dd>
                        {{ request.borrower_name }}
                        <span v-if="request.borrower_department" class="text-muted-foreground">· {{ request.borrower_department }}</span>
                    </dd>
                </div>
                <div v-if="request.borrower_phone">
                    <dt class="text-xs text-muted-foreground">{{ t('requests.borrower_phone') }}</dt>
                    <dd>{{ request.borrower_phone }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('requests.requester') }}</dt>
                    <dd>{{ request.requester_name }} · {{ dateTime(request.requested_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('requests.needed_by') }}</dt>
                    <dd>{{ request.needed_by ?? '-' }}</dd>
                </div>
                <div v-if="request.ticket">
                    <dt class="text-xs text-muted-foreground">{{ t('requests.ticket') }}</dt>
                    <dd>
                        <Link :href="route('service.tickets.show', request.ticket.ulid)" class="text-primary underline-offset-4 hover:underline">
                            {{ request.ticket.ticket_no }}
                        </Link>
                        {{ request.ticket.title }}
                    </dd>
                </div>
                <div v-if="request.contract">
                    <dt class="text-xs text-muted-foreground">{{ t('requests.contract') }}</dt>
                    <dd>{{ request.contract.contract_no }} · {{ request.contract.title }}</dd>
                </div>
                <div v-if="request.approved_by_name">
                    <dt class="text-xs text-muted-foreground">{{ t('requests.approved_by') }}</dt>
                    <dd>{{ request.approved_by_name }} · {{ dateTime(request.approved_at) }}</dd>
                </div>
                <div v-if="request.purpose" class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs text-muted-foreground">{{ t('requests.purpose') }}</dt>
                    <dd class="whitespace-pre-line">{{ request.purpose }}</dd>
                </div>
            </dl>

            <!-- Lines -->
            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('requests.lines_section') }}</h3>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-3 py-2 font-medium">{{ t('requests.columns.item') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('requests.checkout_type') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ t('requests.qty_requested') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ t('requests.qty_approved') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ t('requests.qty_fulfilled') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('requests.status') }}</th>
                                <th v-if="can.approve" class="px-3 py-2 font-medium">{{ t('requests.decision') }}</th>
                                <th v-else-if="can.fulfill || can.return" class="px-3 py-2 font-medium">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="line in request.items" :key="line.id">
                                <tr class="border-t align-top">
                                    <td class="px-3 py-2">
                                        <div class="text-xs text-muted-foreground">{{ t(`requests.item_types.${line.item_type}`) }}</div>
                                        <Link
                                            v-if="line.asset_ulid"
                                            :href="route('asset.assets.show', line.asset_ulid)"
                                            class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                        >
                                            {{ line.item_code }}
                                        </Link>
                                        <span v-else class="font-mono text-xs">{{ line.item_code }}</span>
                                        <div>{{ line.item_name }}</div>
                                        <div v-if="line.note" class="text-xs text-muted-foreground">{{ line.note }}</div>
                                        <div v-if="line.on_hand !== null" class="text-xs text-muted-foreground">
                                            {{ t('requests.on_hand', { qty: line.on_hand, unit: line.unit ?? '' }) }}
                                        </div>
                                        <div v-if="line.reject_reason" class="text-xs text-red-700 dark:text-red-400">
                                            {{ t('requests.reject_reason') }}: {{ line.reject_reason }}
                                        </div>
                                        <div v-if="line.purchase_request" class="flex items-center gap-1 text-xs">
                                            {{ t('requests.purchase_request') }}
                                            <Link
                                                :href="route('inventory.purchase-requests.show', line.purchase_request.ulid)"
                                                class="font-mono text-primary underline-offset-4 hover:underline"
                                            >
                                                {{ line.purchase_request.pr_no }}
                                            </Link>
                                            <PurchaseStatusBadge :status="line.purchase_request.status" />
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        {{ t(`requests.types.${line.checkout_type}`) }}
                                        <div
                                            v-if="line.due_return_date"
                                            class="text-xs"
                                            :class="line.overdue ? 'text-red-700 dark:text-red-400' : 'text-muted-foreground'"
                                        >
                                            {{ t('requests.due_on', { date: line.due_return_date }) }}
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">{{ line.qty_requested }} {{ line.unit ?? '' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">{{ line.qty_approved ?? '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                        {{ line.qty_fulfilled }}
                                        <div v-if="line.remaining > 0 && line.qty_approved" class="text-xs text-muted-foreground">
                                            {{ t('requests.remaining', { qty: line.remaining }) }}
                                        </div>
                                        <div v-if="line.checkout_type === 'loan' && line.qty_fulfilled > 0" class="text-xs text-muted-foreground">
                                            {{ t('requests.returned_qty', { qty: line.qty_returned }) }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <RequestStatusBadge :status="line.status" kind="line" :overdue="line.overdue" />
                                        <div v-if="line.returned_at" class="text-xs text-muted-foreground">
                                            {{ t('requests.returned_by', { name: line.returned_by_name ?? '', at: dateTime(line.returned_at) }) }}
                                            <template v-if="line.return_condition"> · {{ line.return_condition }}</template>
                                        </div>
                                    </td>

                                    <!-- Approval decision per line -->
                                    <td v-if="can.approve" class="min-w-64 px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <Input
                                                v-model.number="decisions[line.id].qty"
                                                type="number"
                                                min="1"
                                                :max="line.qty_requested"
                                                class="h-8 w-20"
                                                :disabled="decisions[line.id].reject"
                                                :aria-label="t('requests.qty_to_approve')"
                                            />
                                            <label class="flex items-center gap-1 text-xs">
                                                <input v-model="decisions[line.id].reject" type="checkbox" />
                                                {{ t('requests.reject_line') }}
                                            </label>
                                        </div>
                                        <Input
                                            v-if="decisions[line.id].reject"
                                            v-model="decisions[line.id].reason"
                                            class="mt-2 h-8"
                                            required
                                            :placeholder="t('requests.reject_reason_placeholder')"
                                        />
                                        <InputError :message="(approveForm.errors as Record<string, string>)[`lines.${line.id}.qty`]" />
                                        <InputError :message="(approveForm.errors as Record<string, string>)[`lines.${line.id}.reject_reason`]" />
                                    </td>

                                    <!-- Hand out / return -->
                                    <td v-else-if="can.fulfill || can.return" class="min-w-56 px-3 py-2">
                                        <div class="flex flex-wrap gap-1">
                                            <template v-if="can.fulfill && toFulfill(line)">
                                                <Button size="sm" @click="openAction(line, 'fulfill')">{{ t('requests.fulfill') }}</Button>
                                                <Button
                                                    v-if="line.status !== 'backordered'"
                                                    size="sm"
                                                    variant="outline"
                                                    @click="backorder(line, false)"
                                                >
                                                    {{ t('requests.backorder') }}
                                                </Button>
                                                <Button
                                                    v-if="can.order && line.item_type === 'part' && !line.purchase_request"
                                                    size="sm"
                                                    variant="outline"
                                                    @click="backorder(line, true)"
                                                >
                                                    {{ t('requests.backorder_order') }}
                                                </Button>
                                                <Button size="sm" variant="ghost" @click="openAction(line, 'cancel')">{{
                                                    t('requests.cancel_rest')
                                                }}</Button>
                                            </template>
                                            <Button
                                                v-if="can.return && canReturn(line)"
                                                size="sm"
                                                variant="outline"
                                                @click="openAction(line, 'return')"
                                            >
                                                {{ t('requests.give_back') }}
                                            </Button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- The line's action form -->
                                <tr v-if="open.id === line.id" class="bg-muted/20">
                                    <td colspan="8" class="px-3 py-3">
                                        <form class="flex flex-wrap items-end gap-3" @submit.prevent="sendLine(line)">
                                            <div v-if="open.mode === 'fulfill' || open.mode === 'return'" class="grid gap-1">
                                                <label :for="`line-qty-${line.id}`" class="text-xs text-muted-foreground">
                                                    {{
                                                        open.mode === 'fulfill'
                                                            ? t('requests.qty_to_fulfill', { max: line.remaining })
                                                            : t('requests.qty_to_return', { max: line.outstanding })
                                                    }}
                                                </label>
                                                <Input
                                                    :id="`line-qty-${line.id}`"
                                                    v-model.number="lineForm.qty"
                                                    type="number"
                                                    min="1"
                                                    :max="open.mode === 'fulfill' ? line.remaining : line.outstanding"
                                                    required
                                                    class="h-9 w-28"
                                                />
                                            </div>
                                            <p
                                                v-if="open.mode === 'fulfill' && line.on_hand !== null && lineForm.qty > line.on_hand"
                                                class="text-xs text-amber-700 dark:text-amber-400"
                                            >
                                                {{ t('requests.over_on_hand', { qty: line.on_hand }) }}
                                            </p>
                                            <div v-if="open.mode === 'return'" class="grid min-w-64 flex-1 gap-1">
                                                <label :for="`line-condition-${line.id}`" class="text-xs text-muted-foreground">{{
                                                    t('requests.condition')
                                                }}</label>
                                                <Input :id="`line-condition-${line.id}`" v-model="lineForm.condition" class="h-9" />
                                            </div>
                                            <div v-if="open.mode === 'cancel'" class="grid min-w-64 flex-1 gap-1">
                                                <label :for="`line-reason-${line.id}`" class="text-xs text-muted-foreground">
                                                    {{ t('requests.cancel_rest_reason', { qty: line.remaining }) }}
                                                </label>
                                                <Input :id="`line-reason-${line.id}`" v-model="lineForm.reason" class="h-9" required />
                                            </div>
                                            <Button size="sm" :disabled="lineForm.processing">{{ t(`requests.do_${open.mode}`) }}</Button>
                                            <Button type="button" size="sm" variant="ghost" @click="closeAction">{{ t('common.cancel') }}</Button>
                                        </form>
                                        <InputError :message="lineError('qty')" />
                                        <InputError :message="lineError('reason')" />
                                        <InputError :message="lineError('condition')" />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Approve / reject the whole request -->
            <section v-if="can.approve" class="space-y-3 rounded-md border p-4">
                <h3 class="text-sm font-semibold">{{ t('requests.approve_section') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('requests.approve_hint') }}</p>
                <div class="flex flex-wrap gap-2">
                    <Button :disabled="approveForm.processing" @click="approve">{{ t('requests.approve') }}</Button>
                    <Button variant="outline" @click="rejecting = !rejecting">{{ t('requests.reject_all') }}</Button>
                </div>
                <form v-if="rejecting" class="space-y-2" @submit.prevent="rejectAll">
                    <label for="reject_reason" class="text-xs text-muted-foreground">{{ t('requests.reject_reason') }}</label>
                    <textarea id="reject_reason" v-model="rejectForm.reject_reason" rows="2" required :class="textareaClass" />
                    <InputError :message="rejectForm.errors.reject_reason" />
                    <Button variant="destructive" :disabled="rejectForm.processing">{{ t('requests.confirm_reject_all') }}</Button>
                </form>
            </section>

            <!-- Hand-outs -->
            <section v-if="fulfillments.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('requests.fulfillments') }}</h3>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-3 py-2 font-medium">{{ t('requests.fulfilled_at') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('requests.columns.item') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ t('requests.qty') }}</th>
                                <th class="px-3 py-2 font-medium">{{ t('requests.fulfilled_by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(f, index) in fulfillments" :key="index" class="border-t">
                                <td class="whitespace-nowrap px-3 py-2">{{ dateTime(f.at) }}</td>
                                <td class="px-3 py-2">{{ f.line.item_name }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right">{{ f.qty }} {{ f.line.unit ?? '' }}</td>
                                <td class="px-3 py-2">{{ f.by ?? '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
