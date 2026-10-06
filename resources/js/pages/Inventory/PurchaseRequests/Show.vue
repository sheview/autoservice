<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PurchaseStatusBadge from '@/components/PurchaseStatusBadge.vue';
import RequestStatusBadge from '@/components/RequestStatusBadge.vue';
import SerialInputs from '@/components/SerialInputs.vue';
import StepProgress from '@/components/StepProgress.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { purchaseSteps } from '@/lib/purchaseSteps';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { PurchaseCheckoutRow, PurchaseEventRow, PurchaseReceiptRow, PurchaseRequestRow } from '@/types/purchase';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ExternalLink, FileDown, PackageCheck, PackagePlus, Printer, Truck } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * A purchase request: its details, the moves (approve, order, cancel), and for the buyers the
 * deliveries (some or all of it, several times) and registering each as an asset or a part.
 * Once registered it is handed out on an issue/loan request ("เบิกต่อ"), whose papers are linked here.
 */
const props = defineProps<{
    request: PurchaseRequestRow;
    contract: { id: number; contract_no: string; title: string } | null;
    receipts: PurchaseReceiptRow[];
    events: PurchaseEventRow[];
    checkouts: PurchaseCheckoutRow[];
    issueUrl: string | null;
    register: {
        asset: boolean;
        part: boolean;
        newPart: boolean;
        categories: { id: number; name: string }[];
        parts: { id: number; code: string; name: string; unit: string; track_serial: boolean }[];
        canSerials: boolean;
    } | null;
    attachments: Attachment[];
    actions: string[];
    needsNote: string[];
    // The items asked for on the same form (one request each), and how many the user may approve / turn down now.
    batch: {
        items: { ulid: string; pr_no: string; item_name: string; quantity: number; unit: string; status: string; current: boolean }[];
        decidable: { approve: number; reject: number };
    } | null;
    can: { update: boolean; attach: boolean; receive: boolean; register: boolean; handOut: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('purchase_requests.title'), href: route('inventory.purchase-requests.index') },
    { title: props.request.pr_no, href: route('inventory.purchase-requests.show', props.request.ulid) },
];

// --- moves: the note box opens for the chosen move; a rejection needs it --------
const pending = ref<string | null>(null);
// The move is for the whole batch (every request of it the user may decide), not this request only.
const wholeBatch = ref(false);
const choose = (action: string, batch = false) => {
    wholeBatch.value = batch;
    pending.value = pending.value === action && !batch ? null : action;
};
const move = useForm({ action: '', note: '' });
const confirm = () => {
    move.action = pending.value ?? '';
    move.post(route(wholeBatch.value ? 'inventory.purchase-requests.move-batch' : 'inventory.purchase-requests.move', props.request.ulid), {
        preserveScroll: true,
        onSuccess: () => {
            pending.value = null;
            wholeBatch.value = false;
            move.reset();
        },
    });
};
const actionVariant = (action: string) => (['reject', 'cancel'].includes(action) ? 'outline' : 'default');

// --- a delivery: how many came and what was read off the goods ----------------
// It goes into the system at once, as what the request says (asked here when it does not say).
const left = computed(() => props.request.quantity - props.request.qty_received);
const choosingKind = ref(props.request.item_kind === null);
const receive = useForm({
    quantity: left.value,
    brand: '',
    model: '',
    unit_price: props.request.unit_price ?? '',
    serials: '',
    note: '',
    item_kind: props.request.item_kind,
    asset_category_id: props.request.asset_category_id,
    location: '',
    part_id: null as number | null,
    track_serial: false,
    hand_out: props.can.handOut && props.request.requested_by !== null,
});
// A part followed by serial number (or a new one to be): one serial box per unit received.
const receiveTracked = computed(() => {
    if ((choosingKind.value ? receive.item_kind : props.request.item_kind) !== 'part') return false;
    if (receive.part_id === null) return receive.track_serial;
    return props.register?.parts.find((p) => p.id === receive.part_id)?.track_serial ?? false;
});
const serialBoxes = computed({
    get: () => receive.serials.split('\n'),
    set: (list: string[]) => (receive.serials = list.join('\n')),
});
const serialCount = computed(() => receive.serials.split(/\r?\n/).filter((line) => line.trim() !== '').length);
const categoryName = computed(() => props.register?.categories.find((c) => c.id === props.request.asset_category_id)?.name ?? '-');
const submitReceive = () =>
    receive
        .transform((data) => ({
            ...data,
            // What it becomes is sent only when chosen here; otherwise the request's is used.
            item_kind: choosingKind.value ? data.item_kind : null,
            asset_category_id: choosingKind.value && data.item_kind === 'asset' ? data.asset_category_id : null,
            part_id: data.item_kind === 'part' ? data.part_id : null,
            track_serial: data.part_id === null && data.track_serial ? true : null,
        }))
        .post(route('inventory.purchase-requests.receipts.store', props.request.ulid), {
            preserveScroll: true,
            onSuccess: () => {
                receive.reset('quantity', 'brand', 'model', 'serials', 'note', 'location');
                receive.quantity = left.value;
                choosingKind.value = props.request.item_kind === null;
            },
        });

// --- what is registered and not handed out: to whoever asked for it, in one go ----
const handOut = useForm({});
const submitHandOut = () => {
    if (
        !window.confirm(
            t('purchase_requests.hand_out.confirm', { name: props.request.requested_by_name ?? '-', qty: toIssue.value, unit: props.request.unit }),
        )
    )
        return;
    handOut.post(route('inventory.purchase-requests.hand-out', props.request.ulid), { preserveScroll: true });
};

// --- registering a delivery: as an asset (category) or stock of a part ----------
const registering = ref<number | null>(null);
const registerForm = useForm({
    as: (props.register?.asset ? 'asset' : 'part') as 'asset' | 'part',
    category_id: null as number | null,
    location: '',
    part_id: null as number | null,
    part_code: '',
    track_serial: false,
});
const openRegister = (receipt: PurchaseReceiptRow) => {
    registerForm.reset();
    registerForm.clearErrors();
    registering.value = receipt.id;
};
const submitRegister = (receipt: PurchaseReceiptRow) =>
    registerForm
        .transform((data) => ({
            as: data.as,
            ...(data.as === 'asset'
                ? { category_id: data.category_id, location: data.location || null }
                : { part_id: data.part_id, part_code: data.part_id ? null : data.part_code, track_serial: data.part_id ? null : data.track_serial }),
        }))
        .post(route('inventory.purchase-requests.receipts.register', [props.request.ulid, receipt.id]), {
            preserveScroll: true,
            onSuccess: () => (registering.value = null),
        });

// --- links: shown with their site, so a reader sees where they lead -----------
const host = (url: string) => {
    try {
        return new URL(url).hostname;
    } catch {
        return url;
    }
};

const stepBar = computed(() => purchaseSteps(props.request));
const toIssue = computed(() => props.request.qty_registered - props.request.qty_issued);
const money = (baht: string | null) => (baht === null ? '-' : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2 }));
const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const textareaClass = 'shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${request.pr_no} ${request.item_name}`" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="flex items-center gap-2 font-mono text-sm text-muted-foreground">
                        {{ request.pr_no }}
                        <PurchaseStatusBadge :status="request.status" />
                    </p>
                    <Heading :title="request.item_name" :description="`${request.quantity} ${request.unit}`" />
                    <p v-if="request.qty_received > 0" class="-mt-4 text-xs text-muted-foreground">
                        {{
                            t('purchase_requests.progress', {
                                received: request.qty_received,
                                registered: request.qty_registered,
                                issued: request.qty_issued,
                                quantity: request.quantity,
                                unit: request.unit,
                            })
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <a :href="route('inventory.purchase-requests.print', request.ulid)" target="_blank" rel="noopener">
                            <Printer class="h-4 w-4" />
                            {{ t('purchase_requests.print') }}
                        </a>
                    </Button>
                    <Button variant="ghost" as-child>
                        <a :href="route('inventory.purchase-requests.pdf', request.ulid)" target="_blank" rel="noopener">{{
                            t('purchase_requests.pdf')
                        }}</a>
                    </Button>
                    <Button v-if="can.update" variant="outline" as-child>
                        <Link :href="route('inventory.purchase-requests.edit', request.ulid)">{{ t('common.edit') }}</Link>
                    </Button>
                </div>
            </div>

            <StepProgress :steps="stepBar.steps" :current="stepBar.current" :state="stepBar.state" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p v-if="page.props.flash.error" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </p>

            <!-- The other items asked for on the same form -->
            <section v-if="batch" class="space-y-2 rounded-md border p-4">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.batch.title', { count: batch.items.length }) }}</h3>
                <ul class="divide-y text-sm">
                    <li v-for="item in batch.items" :key="item.ulid" class="flex flex-wrap items-center gap-3 py-2">
                        <span v-if="item.current" class="font-mono font-semibold">{{ item.pr_no }}</span>
                        <Link
                            v-else
                            :href="route('inventory.purchase-requests.show', item.ulid)"
                            class="font-mono text-primary underline-offset-4 hover:underline"
                            >{{ item.pr_no }}</Link
                        >
                        <span class="min-w-0 flex-1">{{ item.item_name }} × {{ item.quantity }} {{ item.unit }}</span>
                        <PurchaseStatusBadge :status="item.status" />
                    </li>
                </ul>
            </section>

            <!-- What the user may do now -->
            <div v-if="actions.length || batch?.decidable.approve || batch?.decidable.reject" class="space-y-3 rounded-md border p-4">
                <div class="flex flex-wrap gap-2">
                    <Button v-for="action in actions" :key="action" size="sm" :variant="actionVariant(action)" @click="choose(action)">
                        {{ t(`purchase_requests.actions.${action}`) }}
                    </Button>
                    <template v-if="batch && batch.decidable.approve > 1">
                        <Button size="sm" @click="choose('approve', true)">
                            {{ t('purchase_requests.batch.approve', { count: batch.decidable.approve }) }}
                        </Button>
                    </template>
                    <template v-if="batch && batch.decidable.reject > 1">
                        <Button size="sm" variant="outline" @click="choose('reject', true)">
                            {{ t('purchase_requests.batch.reject', { count: batch.decidable.reject }) }}
                        </Button>
                    </template>
                </div>
                <form v-if="pending" class="space-y-2" @submit.prevent="confirm">
                    <label for="note" class="text-sm font-medium"
                        >{{
                            wholeBatch
                                ? t(`purchase_requests.batch.${pending}`, { count: batch?.decidable[pending as 'approve' | 'reject'] ?? 0 })
                                : t(`purchase_requests.actions.${pending}`)
                        }}
                        — {{ t(`purchase_requests.notes.${pending}`) }}</label
                    >
                    <textarea id="note" v-model="move.note" rows="2" :required="needsNote.includes(pending)" :class="textareaClass" />
                    <InputError :message="move.errors.note ?? move.errors.action" />
                    <div class="flex gap-2">
                        <Button size="sm" :variant="pending === 'reject' ? 'destructive' : 'default'" :disabled="move.processing">
                            {{ t('purchase_requests.confirm') }}
                        </Button>
                        <Button size="sm" type="button" variant="ghost" @click="pending = null">{{ t('common.cancel') }}</Button>
                    </div>
                </form>
            </div>

            <!-- Registered and not all handed out: hand it out -->
            <div
                v-if="toIssue > 0 && (issueUrl || can.handOut)"
                class="flex flex-wrap items-center gap-3 rounded-md border border-green-300 bg-green-50 p-4 text-sm dark:border-green-900 dark:bg-green-950"
            >
                <span class="flex-1">{{ t('purchase_requests.hand_out.hint', { qty: toIssue, unit: request.unit }) }}</span>
                <Button v-if="can.handOut && request.requested_by" :disabled="handOut.processing" @click="submitHandOut">
                    <PackageCheck class="h-4 w-4" />
                    {{ t('purchase_requests.hand_out.button') }}
                </Button>
                <Button v-if="issueUrl" :variant="can.handOut ? 'outline' : 'default'" as-child>
                    <Link :href="issueUrl">{{ can.handOut ? t('purchase_requests.hand_out.manual') : t('purchase_requests.issue.button') }}</Link>
                </Button>
                <InputError class="w-full" :message="(handOut.errors as Record<string, string>).hand_out" />
            </div>

            <!-- A delivery -->
            <form v-if="can.receive && left > 0" class="space-y-4 rounded-md border p-4" @submit.prevent="submitReceive">
                <div>
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <Truck class="h-4 w-4" /> {{ t('purchase_requests.receive.title') }}
                    </h3>
                    <p class="text-xs text-muted-foreground">{{ t('purchase_requests.receive.hint', { left, unit: request.unit }) }}</p>
                </div>

                <!-- What it goes into the system as -->
                <div class="space-y-2 rounded-md bg-muted/40 p-3 text-sm">
                    <div v-if="!choosingKind" class="flex flex-wrap items-center gap-2">
                        <span>{{
                            request.item_kind === 'asset'
                                ? t('purchase_requests.kind.current_asset', { category: categoryName })
                                : t('purchase_requests.kind.current_part')
                        }}</span>
                        <button type="button" class="text-xs text-primary underline" @click="choosingKind = true">
                            {{ t('purchase_requests.kind.change') }}
                        </button>
                    </div>
                    <template v-else>
                        <Label>{{ t('purchase_requests.kind.label') }}<span class="text-red-600"> *</span></Label>
                        <div class="flex flex-wrap gap-4">
                            <label v-if="register?.asset" class="flex items-center gap-2"
                                ><input v-model="receive.item_kind" type="radio" value="asset" required />
                                {{ t('purchase_requests.kind.asset') }}</label
                            >
                            <label v-if="register?.part" class="flex items-center gap-2"
                                ><input v-model="receive.item_kind" type="radio" value="part" required />
                                {{ t('purchase_requests.kind.part') }}</label
                            >
                        </div>
                        <InputError :message="receive.errors.item_kind" />
                    </template>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div v-if="choosingKind && receive.item_kind === 'asset'" class="grid content-start gap-1">
                            <Label for="receive_category">{{ t('purchase_requests.kind.category') }}<span class="text-red-600"> *</span></Label>
                            <select id="receive_category" v-model="receive.asset_category_id" required :class="selectClass">
                                <option :value="null" disabled>{{ t('purchase_requests.kind.choose_category') }}</option>
                                <option v-for="category in register?.categories ?? []" :key="category.id" :value="category.id">
                                    {{ category.name }}
                                </option>
                            </select>
                        </div>
                        <div v-if="(choosingKind ? receive.item_kind : request.item_kind) === 'asset'" class="grid content-start gap-1">
                            <Label for="receive_location">{{ t('purchase_requests.register.location') }}</Label>
                            <Input id="receive_location" v-model="receive.location" maxlength="255" />
                        </div>
                        <div v-if="(choosingKind ? receive.item_kind : request.item_kind) === 'part'" class="grid content-start gap-1">
                            <Label for="receive_part">{{ t('purchase_requests.register.part') }}</Label>
                            <select id="receive_part" v-model="receive.part_id" :class="selectClass">
                                <option :value="null">{{ t('purchase_requests.register.part_auto') }}</option>
                                <option v-for="part in register?.parts ?? []" :key="part.id" :value="part.id">
                                    {{ part.code }} · {{ part.name }}{{ part.track_serial ? ` (${t('parts.sn_badge')})` : '' }}
                                </option>
                            </select>
                            <label v-if="receive.part_id === null && register?.canSerials" class="flex items-center gap-2 text-sm">
                                <input v-model="receive.track_serial" type="checkbox" class="size-4 rounded border-input" />
                                {{ t('purchase_requests.receive.new_part_tracked') }}
                            </label>
                        </div>
                    </div>
                    <InputError :message="receive.errors.asset_category_id ?? (receive.errors as Record<string, string>).category_id" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="grid content-start gap-1">
                        <Label for="receive_quantity">{{ t('purchase_requests.receive.quantity') }}<span class="text-red-600"> *</span></Label>
                        <Input id="receive_quantity" v-model.number="receive.quantity" type="number" min="1" :max="left" required />
                        <InputError :message="receive.errors.quantity" />
                    </div>
                    <div class="grid content-start gap-1">
                        <Label for="receive_brand">{{ t('purchase_requests.receive.brand') }}</Label>
                        <Input id="receive_brand" v-model="receive.brand" maxlength="100" />
                        <InputError :message="receive.errors.brand" />
                    </div>
                    <div class="grid content-start gap-1">
                        <Label for="receive_model">{{ t('purchase_requests.receive.model') }}</Label>
                        <Input id="receive_model" v-model="receive.model" maxlength="100" />
                        <InputError :message="receive.errors.model" />
                    </div>
                    <div class="grid content-start gap-1">
                        <Label for="receive_price">{{ t('purchase_requests.receive.unit_price') }}</Label>
                        <Input id="receive_price" v-model="receive.unit_price" type="number" min="0" step="0.01" />
                        <InputError :message="receive.errors.unit_price" />
                    </div>
                    <div v-if="receiveTracked" class="grid content-start gap-1 sm:col-span-4">
                        <SerialInputs id="receive_serials" v-model="serialBoxes" :count="Number(receive.quantity) || 0" />
                        <InputError :message="receive.errors.serials" />
                    </div>
                    <div v-else class="grid content-start gap-1 sm:col-span-2">
                        <Label for="receive_serials">{{ t('purchase_requests.receive.serials') }}</Label>
                        <textarea id="receive_serials" v-model="receive.serials" rows="3" :class="`${textareaClass} font-mono`" />
                        <p class="text-xs text-muted-foreground">{{ t('purchase_requests.receive.serials_count', { count: serialCount }) }}</p>
                        <InputError :message="receive.errors.serials" />
                    </div>
                    <div class="grid content-start gap-1 sm:col-span-2">
                        <Label for="receive_note">{{ t('purchase_requests.receive.note') }}</Label>
                        <textarea id="receive_note" v-model="receive.note" rows="3" :class="textareaClass" />
                        <InputError :message="receive.errors.note" />
                    </div>
                </div>
                <label v-if="can.handOut && request.requested_by" class="flex items-center gap-2 text-sm">
                    <input v-model="receive.hand_out" type="checkbox" class="size-4 rounded border-input" />
                    {{ t('purchase_requests.receive.hand_out', { name: request.requested_by_name ?? '-' }) }}
                </label>
                <Button size="sm" :disabled="receive.processing">{{
                    receive.hand_out && can.handOut ? t('purchase_requests.receive.submit_hand_out') : t('purchase_requests.receive.submit')
                }}</Button>
            </form>

            <dl class="grid gap-x-6 gap-y-4 rounded-md border p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.requested_by') }}</dt>
                    <dd>{{ request.requested_by_name }} · {{ dateTime(request.requested_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.needed_by') }}</dt>
                    <dd>{{ request.needed_by ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.unit_price') }}</dt>
                    <dd class="tabular-nums">{{ money(request.unit_price) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.total') }}</dt>
                    <dd class="font-semibold tabular-nums">{{ money(request.total) }}</dd>
                </div>
                <div v-if="contract" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.contract') }}</dt>
                    <dd>{{ contract.contract_no }} · {{ contract.title }}</dd>
                </div>
                <div v-if="request.description" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.description_field') }}</dt>
                    <dd class="whitespace-pre-line">{{ request.description }}</dd>
                </div>
                <div v-if="request.reason" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-xs text-muted-foreground">{{ t('purchase_requests.reason') }}</dt>
                    <dd class="whitespace-pre-line">{{ request.reason }}</dd>
                </div>
            </dl>

            <!-- Deliveries, and what each became -->
            <section v-if="receipts.length || can.receive" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.receipts.title') }}</h3>
                <p v-if="!receipts.length" class="text-sm text-muted-foreground">{{ t('purchase_requests.receipts.none') }}</p>
                <ul v-else class="divide-y rounded-md border text-sm">
                    <li v-for="receipt in receipts" :key="receipt.id" class="space-y-2 px-4 py-3">
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="font-medium">
                                    {{ receipt.quantity }} {{ request.unit }}
                                    <span v-if="receipt.brand || receipt.model" class="font-normal text-muted-foreground">
                                        · {{ [receipt.brand, receipt.model].filter(Boolean).join(' ') }}
                                    </span>
                                    <span v-if="receipt.unit_price" class="font-normal tabular-nums text-muted-foreground">
                                        · {{ money(receipt.unit_price) }}</span
                                    >
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{
                                        t('purchase_requests.receipts.received_by', {
                                            name: receipt.received_by_name ?? '-',
                                            at: dateTime(receipt.received_at),
                                        })
                                    }}
                                </div>
                                <div v-if="receipt.serials.length" class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="serial in receipt.serials" :key="serial" class="rounded bg-muted px-1.5 font-mono text-xs">{{
                                        serial
                                    }}</span>
                                </div>
                                <p v-if="receipt.note" class="whitespace-pre-line text-xs text-muted-foreground">{{ receipt.note }}</p>
                            </div>
                            <div class="text-right text-xs">
                                <template v-if="receipt.registered_as === 'asset'">
                                    <span class="text-muted-foreground">{{ t('purchase_requests.receipts.registered_asset') }}</span>
                                    <Link
                                        v-for="asset in receipt.assets"
                                        :key="asset.ulid"
                                        :href="route('asset.assets.show', asset.ulid)"
                                        class="ml-1 font-mono text-primary underline-offset-4 hover:underline"
                                        >{{ asset.asset_code }}</Link
                                    >
                                </template>
                                <template v-else-if="receipt.registered_as === 'part'">
                                    <span class="text-muted-foreground">{{ t('purchase_requests.receipts.registered_part') }}</span>
                                    <Link
                                        v-if="receipt.part"
                                        :href="route('inventory.parts.show', receipt.part.id)"
                                        class="ml-1 font-mono text-primary underline-offset-4 hover:underline"
                                        >{{ receipt.part.code }}</Link
                                    >
                                </template>
                                <template v-else>
                                    <span class="text-amber-700 dark:text-amber-400">{{ t('purchase_requests.receipts.not_registered') }}</span>
                                    <Button
                                        v-if="register && registering !== receipt.id"
                                        size="sm"
                                        variant="outline"
                                        class="ml-2"
                                        @click="openRegister(receipt)"
                                    >
                                        <PackagePlus class="h-4 w-4" />
                                        {{ t('purchase_requests.receipts.register') }}
                                    </Button>
                                </template>
                            </div>
                        </div>

                        <!-- Register this delivery -->
                        <form
                            v-if="register && registering === receipt.id"
                            class="space-y-3 rounded-md bg-muted/40 p-3"
                            @submit.prevent="submitRegister(receipt)"
                        >
                            <p class="text-xs text-muted-foreground">{{ t('purchase_requests.register.hint') }}</p>
                            <div class="flex flex-wrap gap-4 text-sm">
                                <label v-if="register.asset" class="flex items-center gap-2">
                                    <input v-model="registerForm.as" type="radio" value="asset" /> {{ t('purchase_requests.register.as_asset') }}
                                </label>
                                <label v-if="register.part" class="flex items-center gap-2">
                                    <input v-model="registerForm.as" type="radio" value="part" /> {{ t('purchase_requests.register.as_part') }}
                                </label>
                            </div>
                            <div v-if="registerForm.as === 'asset'" class="grid gap-3 sm:grid-cols-2">
                                <div class="grid content-start gap-1">
                                    <Label :for="`category-${receipt.id}`"
                                        >{{ t('purchase_requests.register.category') }}<span class="text-red-600"> *</span></Label
                                    >
                                    <select :id="`category-${receipt.id}`" v-model="registerForm.category_id" required :class="selectClass">
                                        <option :value="null" disabled>{{ t('purchase_requests.register.choose_category') }}</option>
                                        <option v-for="category in register.categories" :key="category.id" :value="category.id">
                                            {{ category.name }}
                                        </option>
                                    </select>
                                    <InputError :message="registerForm.errors.category_id" />
                                </div>
                                <div class="grid content-start gap-1">
                                    <Label :for="`location-${receipt.id}`">{{ t('purchase_requests.register.location') }}</Label>
                                    <Input :id="`location-${receipt.id}`" v-model="registerForm.location" maxlength="255" />
                                </div>
                            </div>
                            <div v-else class="grid gap-3 sm:grid-cols-2">
                                <div class="grid content-start gap-1">
                                    <Label :for="`part-${receipt.id}`">{{ t('purchase_requests.register.part') }}</Label>
                                    <select :id="`part-${receipt.id}`" v-model="registerForm.part_id" :class="selectClass">
                                        <option v-if="register.newPart" :value="null">{{ t('purchase_requests.register.new_part') }}</option>
                                        <option v-for="part in register.parts" :key="part.id" :value="part.id">
                                            {{ part.code }} · {{ part.name }}{{ part.track_serial ? ` (${t('parts.sn_badge')})` : '' }}
                                        </option>
                                    </select>
                                    <InputError :message="registerForm.errors.part_id" />
                                </div>
                                <div v-if="registerForm.part_id === null" class="grid content-start gap-1">
                                    <Label :for="`part-code-${receipt.id}`">{{ t('purchase_requests.register.part_code') }}</Label>
                                    <Input
                                        :id="`part-code-${receipt.id}`"
                                        v-model="registerForm.part_code"
                                        maxlength="30"
                                        class="font-mono"
                                        :placeholder="t('purchase_requests.register.part_code_auto')"
                                    />
                                    <p class="text-xs text-muted-foreground">{{ t('purchase_requests.register.part_code_hint') }}</p>
                                    <InputError :message="registerForm.errors.part_code" />
                                    <label v-if="register.canSerials" class="flex items-center gap-2 text-sm">
                                        <input v-model="registerForm.track_serial" type="checkbox" class="size-4 rounded border-input" />
                                        {{ t('purchase_requests.receive.new_part_tracked') }}
                                    </label>
                                </div>
                            </div>
                            <InputError :message="registerForm.errors.as ?? (registerForm.errors as Record<string, string>).serials" />
                            <div class="flex gap-2">
                                <Button size="sm" :disabled="registerForm.processing">{{ t('purchase_requests.register.submit') }}</Button>
                                <Button size="sm" type="button" variant="ghost" @click="registering = null">{{ t('common.cancel') }}</Button>
                            </div>
                        </form>
                    </li>
                </ul>
            </section>

            <!-- Issue/loan requests it was asked from or handed out on, with their papers -->
            <section v-if="checkouts.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.checkouts.title') }}</h3>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="checkout in checkouts" :key="checkout.ulid" class="flex flex-wrap items-center gap-3 px-4 py-2">
                        <Link :href="route('asset.requests.show', checkout.ulid)" class="font-mono text-primary underline-offset-4 hover:underline">
                            {{ checkout.request_no }}
                        </Link>
                        <RequestStatusBadge :status="checkout.status" />
                        <span class="text-muted-foreground">{{ checkout.borrower_name }}</span>
                        <span v-if="checkout.source" class="rounded bg-muted px-1.5 text-xs">{{ t('purchase_requests.checkouts.source') }}</span>
                        <span class="flex-1" />
                        <Button v-if="checkout.printable" size="sm" variant="outline" as-child>
                            <a :href="route('asset.requests.pdf', checkout.ulid)" target="_blank" rel="noopener">
                                <FileDown class="h-4 w-4" />
                                {{ t('purchase_requests.checkouts.checkout_pdf') }}
                            </a>
                        </Button>
                        <Button v-if="checkout.delivered" size="sm" variant="outline" as-child>
                            <a :href="route('asset.requests.delivery-note', checkout.ulid)" target="_blank" rel="noopener">
                                <FileDown class="h-4 w-4" />
                                {{ t('purchase_requests.checkouts.delivery_pdf') }}
                            </a>
                        </Button>
                    </li>
                </ul>
            </section>

            <section v-if="request.links.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.links') }}</h3>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="link in request.links" :key="link" class="flex items-center gap-2 px-4 py-2">
                        <ExternalLink class="h-4 w-4 shrink-0 text-muted-foreground" />
                        <a
                            :href="link"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="min-w-0 flex-1 truncate text-primary underline-offset-4 hover:underline"
                        >
                            {{ link }}
                        </a>
                        <span class="shrink-0 rounded bg-muted px-1.5 text-xs text-muted-foreground">{{ host(link) }}</span>
                    </li>
                </ul>
                <p class="text-xs text-muted-foreground">{{ t('purchase_requests.link_warning') }}</p>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.quotations') }}</h3>
                <AttachmentList
                    :attachments="attachments"
                    :title="false"
                    :can-delete="can.attach"
                    :upload-url="can.attach ? route('inventory.purchase-requests.attachments.store', request.ulid) : null"
                />
            </section>

            <!-- Every step: what, who, when -->
            <section v-if="events.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('purchase_requests.history') }}</h3>
                <ul class="space-y-2 border-l pl-4 text-sm">
                    <li v-for="event in events" :key="event.id">
                        <span class="font-medium">{{ t(`purchase_requests.event_actions.${event.action}`) }}</span>
                        <span class="ml-2"><PurchaseStatusBadge :status="event.to_status" /></span>
                        <span class="text-xs text-muted-foreground"> · {{ event.actor_name ?? '-' }} · {{ dateTime(event.at) }}</span>
                        <p v-if="event.note" class="whitespace-pre-line text-muted-foreground">{{ event.note }}</p>
                    </li>
                </ul>
            </section>

            <Button variant="outline" as-child>
                <Link :href="route('inventory.purchase-requests.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
