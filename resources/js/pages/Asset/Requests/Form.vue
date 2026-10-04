<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { CheckoutItemOption, CheckoutRequestRow, CheckoutTicketOption } from '@/types/checkout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, ShoppingCart, Trash2, X } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Writing an issue/loan request with several lines (assets and parts): saved as a draft or sent
 * for approval. Parts are used on a job, so a request with parts needs its ticket.
 */
const props = defineProps<{
    // Editing a draft; null = a new request.
    request: CheckoutRequestRow | null;
    borrowers: { id: number; name: string }[];
    contracts: { id: number; label: string }[];
    partsEnabled: boolean;
    maxItems: number;
    // May open a purchase request for what is not there (or not free).
    canPurchase: boolean;
    can: { view: boolean; create: boolean; forOthers: boolean; approve: boolean; fulfill: boolean; return: boolean };
    // Start with this line (?asset= / ?part=) and this ticket (?ticket=).
    firstItem: CheckoutItemOption | null;
    ticket: CheckoutTicketOption | null;
    // ?purchase_request=: what that purchase brought, as lines tied to it ("เบิกต่อ").
    purchase: {
        ulid: string;
        pr_no: string;
        borrower_user_id: number | null;
        contract_id: number | null;
        items: (CheckoutItemOption & { qty: number; purchase_request_id: number })[];
    } | null;
}>();

interface Line {
    item_type: 'asset' | 'part';
    asset_id: number | null;
    part_id: number | null;
    code: string | null;
    name: string;
    detail: string | null;
    unit: string | null;
    lot: boolean;
    // null = not known yet (a saved draft before its items are looked up).
    available: number | null;
    checkout_type: 'issue' | 'loan';
    qty: number;
    due_return_date: string;
    note: string;
    // Bought on this purchase request: handing it out counts there.
    purchase_request_id: number | null;
}

const page = usePage<SharedData>();
const me = page.props.auth.user as { id: number; name: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('requests.title'), href: route('asset.requests.index') },
    ...(props.request
        ? [
              { title: props.request.request_no, href: route('asset.requests.show', props.request.ulid) },
              { title: t('common.edit'), href: route('asset.requests.edit', props.request.ulid) },
          ]
        : [{ title: t('requests.new'), href: route('asset.requests.create') }]),
];

const lineFrom = (option: CheckoutItemOption): Line => ({
    item_type: option.item_type,
    asset_id: option.asset_id,
    part_id: option.part_id,
    code: option.code,
    name: option.name,
    detail: option.detail,
    unit: option.unit,
    lot: option.lot,
    available: option.available,
    checkout_type: option.item_type === 'part' ? 'issue' : 'loan',
    qty: 1,
    due_return_date: '',
    note: '',
    purchase_request_id: null,
});

const initialLines = (): Line[] => {
    if (props.request) {
        return props.request.items.map((item) => ({
            item_type: item.item_type,
            asset_id: item.asset_id,
            part_id: item.part_id,
            code: item.item_code,
            name: item.item_name,
            detail: null,
            unit: item.unit,
            lot: item.item_type === 'part' || item.qty_requested > 1,
            available: null,
            checkout_type: item.checkout_type,
            qty: item.qty_requested,
            due_return_date: item.due_return_date ?? '',
            note: item.note ?? '',
            purchase_request_id: item.purchase_request_id,
        }));
    }
    return props.firstItem ? [lineFrom(props.firstItem)] : [];
};

// What the purchase brought, added to the lines (once each).
const purchaseLines = (): Line[] =>
    (props.purchase?.items ?? []).map((option) => ({
        ...lineFrom(option),
        checkout_type: 'issue',
        qty: option.lot ? option.qty : 1,
        purchase_request_id: option.purchase_request_id,
    }));

const r = props.request;
const borrowerKind = ref<'staff' | 'other'>(r && r.borrower_user_id === null ? 'other' : 'staff');
// A new request for a purchase is for whoever asked for it, by default.
const purchaseBorrower =
    props.can.forOthers && props.purchase?.borrower_user_id && props.borrowers.some((u) => u.id === props.purchase?.borrower_user_id);
const form = useForm({
    // Oneself by default, unless not one of the staff listed (e.g. central staff inside the company).
    borrower_user_id: (r
        ? r.borrower_user_id
        : purchaseBorrower
          ? props.purchase!.borrower_user_id
          : !props.can.forOthers || props.borrowers.some((u) => u.id === me.id)
            ? me.id
            : null) as number | null,
    borrower_name: r && r.borrower_user_id === null ? r.borrower_name : '',
    borrower_department: r?.borrower_department ?? '',
    borrower_phone: r?.borrower_phone ?? '',
    contract_id: (r ? r.contract_id : (props.purchase?.contract_id ?? null)) as number | null,
    purpose: r?.purpose ?? '',
    needed_by: r?.needed_by ?? '',
});

// --- Ticket ---------------------------------------------------------------------------------
const chosenTicket = ref<CheckoutTicketOption | null>(props.ticket);
const ticketSearch = ref('');
const ticketResults = ref<CheckoutTicketOption[]>([]);
const ticketOpen = ref(false);
// The lines, kept beside the form (its data type cannot hold them); sent by save().
const lines = ref<Line[]>(initialLines());
for (const line of purchaseLines()) {
    if (
        !lines.value.some(
            (l) => l.item_type === line.item_type && (line.item_type === 'asset' ? l.asset_id === line.asset_id : l.part_id === line.part_id),
        )
    ) {
        lines.value.push(line);
    }
}
const hasParts = computed(() => lines.value.some((line) => line.item_type === 'part'));

const getJson = async <T,>(url: string): Promise<T> => {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error(String(response.status));
    return (await response.json()) as T;
};

let ticketTimer: ReturnType<typeof setTimeout> | undefined;
const findTickets = () => {
    clearTimeout(ticketTimer);
    ticketTimer = setTimeout(async () => {
        try {
            ticketResults.value = await getJson<CheckoutTicketOption[]>(route('asset.requests.tickets', { q: ticketSearch.value }));
            ticketOpen.value = true;
        } catch {
            ticketResults.value = [];
        }
    }, 300);
};
const pickTicket = (ticket: CheckoutTicketOption) => {
    chosenTicket.value = ticket;
    ticketOpen.value = false;
    ticketSearch.value = '';
};

// --- Line picker ----------------------------------------------------------------------------
const itemSearch = ref('');
const itemResults = ref<{ assets: CheckoutItemOption[]; parts: CheckoutItemOption[] } | null>(null);
const searching = ref(false);
let itemTimer: ReturnType<typeof setTimeout> | undefined;

watch(itemSearch, () => {
    clearTimeout(itemTimer);
    if (itemSearch.value.trim() === '') {
        itemResults.value = null;
        return;
    }
    itemTimer = setTimeout(async () => {
        searching.value = true;
        try {
            itemResults.value = await getJson(route('asset.requests.items', { q: itemSearch.value.trim() }));
        } catch {
            itemResults.value = { assets: [], parts: [] };
        } finally {
            searching.value = false;
        }
    }, 300);
});

const sameItem = (line: Line, option: CheckoutItemOption) =>
    line.item_type === option.item_type && (option.item_type === 'asset' ? line.asset_id === option.asset_id : line.part_id === option.part_id);
const added = (option: CheckoutItemOption) => lines.value.some((line) => sameItem(line, option));
const full = computed(() => lines.value.length >= props.maxItems);

const addLine = (option: CheckoutItemOption) => {
    if (added(option) || full.value) return;
    lines.value.push(lineFrom(option));
};
const removeLine = (index: number) => lines.value.splice(index, 1);

// The purchase request form, started with the item's name. With lines already written, the
// request is saved as a draft first and the purchase is tied to it (nothing is lost).
const purchaseUrl = (item: string) => route('inventory.purchase-requests.create', { item });
const askPurchase = (item: string) => {
    if (lines.value.length === 0) {
        router.visit(purchaseUrl(item));
        return;
    }
    save(false, item);
};

// A saved draft knows its lines but not what is free now: look each one up once.
onMounted(() => {
    lines.value.forEach(async (line) => {
        if (line.available !== null || !line.code) return;
        try {
            const found = await getJson<{ assets: CheckoutItemOption[]; parts: CheckoutItemOption[] }>(
                route('asset.requests.items', { q: line.code }),
            );
            const option = [...found.assets, ...found.parts].find((o) => sameItem(line, o));
            if (option) {
                line.available = option.available;
                line.lot = option.lot;
                line.detail = option.detail;
            }
        } catch {
            // Leave it unknown: the server checks again on save.
        }
    });
});

const lineErrors = (index: number) =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === `items.${index}` || key.startsWith(`items.${index}.`))
        .map(([, message]) => message);

// --- Save -----------------------------------------------------------------------------------
const save = (submit: boolean, thenPurchase: string | null = null) => {
    form.transform((data) => ({
        borrower_user_id: !props.can.forOthers ? me.id : borrowerKind.value === 'staff' ? data.borrower_user_id : null,
        borrower_name: props.can.forOthers && borrowerKind.value === 'other' ? data.borrower_name : null,
        borrower_department: data.borrower_department || null,
        borrower_phone: data.borrower_phone || null,
        ticket_id: chosenTicket.value?.id ?? null,
        contract_id: data.contract_id,
        purpose: data.purpose || null,
        needed_by: data.needed_by || null,
        submit,
        then_purchase: thenPurchase,
        items: lines.value.map((line) => ({
            item_type: line.item_type,
            asset_id: line.asset_id,
            part_id: line.part_id,
            checkout_type: line.item_type === 'part' ? 'issue' : line.checkout_type,
            qty: line.lot ? line.qty : 1,
            due_return_date: line.item_type === 'asset' && line.checkout_type === 'loan' ? line.due_return_date || null : null,
            note: line.note || null,
            purchase_request_id: line.purchase_request_id,
        })),
    }));
    if (props.request) {
        form.put(route('asset.requests.update', props.request.ulid), { preserveScroll: true });
    } else {
        form.post(route('asset.requests.store'), { preserveScroll: true });
    }
};

const today = new Date().toLocaleDateString('sv-SE');
const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const title = props.request ? t('requests.edit_title', { no: props.request.request_no }) : t('requests.new');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <form class="space-y-6 p-4" @submit.prevent="save(true)">
            <Heading :title="title" :description="t('requests.form_description')" />
            <InputError :message="(form.errors as Record<string, string>).request" />
            <p v-if="purchase" class="rounded-md bg-muted/60 px-4 py-2 text-sm">{{ t('requests.from_purchase', { no: purchase.pr_no }) }}</p>

            <!-- Who and what for -->
            <section class="grid gap-4 rounded-md border p-4 sm:grid-cols-2">
                <h3 class="text-sm font-semibold sm:col-span-2">{{ t('requests.header_section') }}</h3>

                <template v-if="can.forOthers">
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label>{{ t('requests.borrower') }}</Label>
                        <div class="flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2">
                                <input v-model="borrowerKind" type="radio" value="staff" />
                                {{ t('requests.borrower_staff') }}
                            </label>
                            <label class="flex items-center gap-2">
                                <input v-model="borrowerKind" type="radio" value="other" />
                                {{ t('requests.borrower_other') }}
                            </label>
                        </div>
                    </div>
                    <div v-if="borrowerKind === 'staff'" class="grid content-start gap-2">
                        <Label for="borrower_user_id" required>{{ t('requests.borrower_name') }}</Label>
                        <select id="borrower_user_id" v-model="form.borrower_user_id" required :class="selectClass">
                            <option :value="null" disabled>{{ t('requests.choose_staff') }}</option>
                            <option v-for="user in borrowers" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <InputError :message="form.errors.borrower_user_id" />
                    </div>
                    <div v-else class="grid content-start gap-2">
                        <Label for="borrower_name" required>{{ t('requests.borrower_name') }}</Label>
                        <Input id="borrower_name" v-model="form.borrower_name" required />
                        <InputError :message="form.errors.borrower_name" />
                    </div>
                </template>
                <div v-else class="grid content-start gap-2 sm:col-span-2">
                    <Label>{{ t('requests.borrower') }}</Label>
                    <p class="text-sm">
                        {{ me.name }} <span class="text-muted-foreground">· {{ t('requests.for_self') }}</span>
                    </p>
                </div>

                <div class="grid content-start gap-2">
                    <Label for="borrower_department">{{ t('requests.borrower_department') }}</Label>
                    <Input id="borrower_department" v-model="form.borrower_department" />
                    <InputError :message="form.errors.borrower_department" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="borrower_phone">{{ t('requests.borrower_phone') }}</Label>
                    <Input id="borrower_phone" v-model="form.borrower_phone" type="tel" />
                    <InputError :message="form.errors.borrower_phone" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="needed_by">{{ t('requests.needed_by') }}</Label>
                    <Input id="needed_by" v-model="form.needed_by" type="date" :min="today" />
                    <InputError :message="form.errors.needed_by" />
                </div>
                <div v-if="contracts.length" class="grid content-start gap-2">
                    <Label for="contract_id">{{ t('requests.contract') }}</Label>
                    <select id="contract_id" v-model="form.contract_id" :class="selectClass">
                        <option :value="null">{{ t('requests.no_contract') }}</option>
                        <option v-for="contract in contracts" :key="contract.id" :value="contract.id">{{ contract.label }}</option>
                    </select>
                    <InputError :message="form.errors.contract_id" />
                </div>

                <!-- Ticket -->
                <div class="grid content-start gap-2 sm:col-span-2">
                    <Label for="ticket_search" :required="hasParts">{{ t('requests.ticket') }}</Label>
                    <div v-if="chosenTicket" class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                        <span class="font-mono text-xs">{{ chosenTicket.ticket_no }}</span>
                        <span class="flex-1">{{ chosenTicket.title }}</span>
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground"
                            :aria-label="t('requests.clear_ticket')"
                            @click="chosenTicket = null"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div v-else class="relative">
                        <Input
                            id="ticket_search"
                            v-model="ticketSearch"
                            type="search"
                            autocomplete="off"
                            :placeholder="t('requests.ticket_placeholder')"
                            @input="findTickets"
                            @focus="findTickets"
                        />
                        <ul v-if="ticketOpen" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border bg-background shadow-md">
                            <li v-for="ticket in ticketResults" :key="ticket.id">
                                <button
                                    type="button"
                                    class="flex w-full gap-2 px-3 py-2 text-left text-sm hover:bg-muted"
                                    @click="pickTicket(ticket)"
                                >
                                    <span class="font-mono text-xs">{{ ticket.ticket_no }}</span>
                                    <span>{{ ticket.title }}</span>
                                </button>
                            </li>
                            <li v-if="ticketResults.length === 0" class="px-3 py-2 text-sm text-muted-foreground">{{ t('requests.no_tickets') }}</li>
                        </ul>
                    </div>
                    <p
                        v-if="hasParts && !chosenTicket"
                        class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        {{ t('requests.ticket_required') }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">{{ t('requests.ticket_hint') }}</p>
                    <InputError :message="(form.errors as Record<string, string>).ticket_id" />
                </div>

                <div class="grid content-start gap-2 sm:col-span-2">
                    <Label for="purpose">{{ t('requests.purpose') }}</Label>
                    <textarea
                        id="purpose"
                        v-model="form.purpose"
                        rows="2"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.purpose" />
                </div>
            </section>

            <!-- Lines -->
            <section class="space-y-4 rounded-md border p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-semibold">{{ t('requests.lines_section') }}</h3>
                    <span class="text-xs text-muted-foreground">{{ t('requests.lines_max', { count: lines.length, max: maxItems }) }}</span>
                </div>

                <div class="space-y-2">
                    <Label for="item_search" required>{{ t('requests.add_item') }}</Label>
                    <Input
                        id="item_search"
                        v-model="itemSearch"
                        type="search"
                        autocomplete="off"
                        :disabled="full"
                        :placeholder="partsEnabled ? t('requests.item_placeholder') : t('requests.item_placeholder_assets')"
                    />
                    <p v-if="full" class="text-xs text-amber-700 dark:text-amber-400">{{ t('requests.lines_full', { max: maxItems }) }}</p>
                    <p v-if="searching" class="text-xs text-muted-foreground">{{ t('requests.searching') }}</p>
                    <div v-else-if="itemResults" class="max-h-80 overflow-y-auto rounded-md border">
                        <template v-for="group in ['assets', 'parts'] as const" :key="group">
                            <template v-if="itemResults[group].length">
                                <div class="bg-muted/50 px-3 py-1 text-xs font-medium">{{ t(`requests.groups.${group}`) }}</div>
                                <div
                                    v-for="option in itemResults[group]"
                                    :key="`${option.item_type}-${option.asset_id ?? option.part_id}`"
                                    class="flex items-center gap-3 border-t px-3 py-2 text-sm"
                                >
                                    <div class="min-w-0 flex-1">
                                        <span class="font-mono text-xs">{{ option.code }}</span>
                                        {{ option.name }}
                                        <div v-if="option.detail" class="truncate text-xs text-muted-foreground">{{ option.detail }}</div>
                                    </div>
                                    <span
                                        class="whitespace-nowrap text-xs"
                                        :class="option.available > 0 ? 'text-muted-foreground' : 'text-amber-700 dark:text-amber-400'"
                                    >
                                        {{ t('requests.available', { qty: option.available, unit: option.unit ?? '' }) }}
                                    </span>
                                    <Button
                                        v-if="canPurchase && option.available <= 0"
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        :disabled="form.processing"
                                        @click="askPurchase(option.name)"
                                    >
                                        <ShoppingCart class="h-4 w-4" />
                                        {{ t('requests.purchase') }}
                                    </Button>
                                    <span v-if="added(option)" class="text-xs text-muted-foreground">{{ t('requests.added') }}</span>
                                    <Button v-else type="button" size="sm" variant="outline" :disabled="full" @click="addLine(option)">
                                        <Plus class="h-4 w-4" />
                                        {{ t('requests.add') }}
                                    </Button>
                                </div>
                            </template>
                        </template>
                        <div
                            v-if="itemResults.assets.length === 0 && itemResults.parts.length === 0"
                            class="space-y-2 px-3 py-4 text-center text-sm text-muted-foreground"
                        >
                            <p>{{ t('requests.no_items_found') }}</p>
                            <Button
                                v-if="canPurchase"
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="form.processing"
                                @click="askPurchase(itemSearch.trim())"
                            >
                                <ShoppingCart class="h-4 w-4" />
                                {{ t('requests.purchase_not_found', { item: itemSearch.trim() }) }}
                            </Button>
                            <p v-if="canPurchase && lines.length" class="text-xs">{{ t('requests.purchase_saves_draft') }}</p>
                        </div>
                    </div>
                </div>

                <InputError :message="(form.errors as Record<string, string>).items" />
                <p v-if="lines.length === 0" class="text-sm text-muted-foreground">{{ t('requests.no_lines_yet') }}</p>

                <div
                    v-for="(line, index) in lines"
                    :key="`${line.item_type}-${line.asset_id ?? line.part_id}`"
                    class="space-y-3 rounded-md border p-3"
                >
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="text-xs text-muted-foreground">{{ t(`requests.item_types.${line.item_type}`) }}</div>
                            <div class="text-sm">
                                <span class="font-mono text-xs">{{ line.code }}</span>
                                {{ line.name }}
                            </div>
                            <div v-if="line.detail" class="text-xs text-muted-foreground">{{ line.detail }}</div>
                        </div>
                        <Button type="button" size="sm" variant="ghost" :aria-label="t('requests.remove_line')" @click="removeLine(index)">
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-4">
                        <div class="grid content-start gap-1">
                            <Label :for="`qty-${index}`" required>{{ t('requests.qty') }}</Label>
                            <div class="flex items-center gap-2">
                                <Input v-if="line.lot" :id="`qty-${index}`" v-model.number="line.qty" type="number" min="1" required class="w-28" />
                                <span v-else :id="`qty-${index}`" class="text-sm">1</span>
                                <span class="text-sm text-muted-foreground">{{ line.unit }}</span>
                            </div>
                            <p v-if="line.available !== null" class="text-xs text-muted-foreground">
                                {{ t('requests.available', { qty: line.available, unit: line.unit ?? '' }) }}
                            </p>
                        </div>
                        <template v-if="line.item_type === 'asset'">
                            <div class="grid content-start gap-1">
                                <Label :for="`type-${index}`">{{ t('requests.checkout_type') }}</Label>
                                <select :id="`type-${index}`" v-model="line.checkout_type" :class="selectClass">
                                    <option value="loan">{{ t('requests.types.loan') }}</option>
                                    <option value="issue">{{ t('requests.types.issue') }}</option>
                                </select>
                            </div>
                            <div v-if="line.checkout_type === 'loan'" class="grid content-start gap-1">
                                <Label :for="`due-${index}`" required>{{ t('requests.due_return_date') }}</Label>
                                <Input :id="`due-${index}`" v-model="line.due_return_date" type="date" :min="today" required />
                            </div>
                        </template>
                        <p v-else class="text-xs text-muted-foreground sm:col-span-2">{{ t('requests.part_issue_hint') }}</p>
                        <div
                            class="grid content-start gap-1"
                            :class="line.item_type === 'asset' && line.checkout_type === 'loan' ? '' : 'sm:col-start-4'"
                        >
                            <Label :for="`note-${index}`">{{ t('requests.note') }}</Label>
                            <Input :id="`note-${index}`" v-model="line.note" />
                        </div>
                    </div>

                    <p
                        v-if="line.available !== null && (line.lot ? line.qty : 1) > line.available"
                        class="flex flex-wrap items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <span class="flex-1">
                            {{
                                line.item_type === 'part'
                                    ? t('requests.short_part', { qty: line.available })
                                    : t('requests.short_asset', { qty: line.available })
                            }}
                        </span>
                        <button
                            v-if="canPurchase && !line.purchase_request_id"
                            type="button"
                            class="inline-flex items-center gap-1 font-medium underline"
                            :disabled="form.processing"
                            @click="askPurchase(line.name)"
                        >
                            <ShoppingCart class="h-3 w-3" />
                            {{ t('requests.purchase') }}
                        </button>
                    </p>
                    <InputError v-for="message in lineErrors(index)" :key="message" :message="message" />
                </div>
            </section>

            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="form.processing || lines.length === 0">{{ t('requests.send') }}</Button>
                <Button type="button" variant="outline" :disabled="form.processing || lines.length === 0" @click="save(false)">
                    {{ t('requests.save_draft') }}
                </Button>
                <Button type="button" variant="ghost" as-child>
                    <Link :href="request ? route('asset.requests.show', request.ulid) : route('asset.requests.index')">{{ t('common.cancel') }}</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
