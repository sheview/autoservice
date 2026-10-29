<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ItemPurposeFields, { type ItemPurposeContract, type ItemPurposeValue, purposeLike } from '@/components/ItemPurposeFields.vue';
import ListThumb from '@/components/ListThumb.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import type { CheckoutItemOption, CheckoutRequestRow, CheckoutTicketOption } from '@/types/checkout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CopyCheck, Plus, ShoppingCart, Trash2, X } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Writing an issue/loan request with several lines (assets and parts): saved as a draft or sent
 * for approval. Each line says what it is for, for which customer and on which ticket (a new line
 * starts with those of the line before); parts are used on a job, so a part line needs its ticket.
 * Replacing a broken unit needs its serial or a photo.
 */
const props = defineProps<{
    // Editing a draft; null = a new request.
    request: CheckoutRequestRow | null;
    borrowers: { id: number; name: string }[];
    // The projects a line can be for, with their customer.
    contracts: ItemPurposeContract[];
    // The customers a line can be for (empty: the field is typed).
    customers: { id: number; name: string }[];
    purposes: string[];
    servicesEnabled: boolean;
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
        purpose: string | null;
        purpose_other: string | null;
        for_internal: boolean;
        customer_name: string | null;
        replaced_serial: string | null;
        ticket: CheckoutTicketOption | null;
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
    photo: string | null;
    // What it is for, for whom and on which job.
    about: ItemPurposeValue;
    // A saved draft: the photo of the broken unit it has.
    kept_photo_id: number | null;
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

// A new line is for what the last one is for (the first: the ticket the page was opened with).
const nextAbout = (): ItemPurposeValue => {
    const last = lines.value.at(-1)?.about;
    return last ? purposeLike(last) : { ...purposeLike(undefined), ticket: props.ticket };
};

const lineFrom = (option: CheckoutItemOption, about: ItemPurposeValue = nextAbout()): Line => ({
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
    photo: option.photo,
    about,
    kept_photo_id: null,
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
            photo: null,
            about: {
                purpose: item.purpose ?? '',
                purpose_other: item.purpose_other ?? '',
                for_internal: item.for_internal,
                customer_name: item.customer_name ?? '',
                contract_id: item.contract_id,
                // The line's ticket; a line from before has only the request's.
                ticket: item.ticket ?? (item.ticket_id === null ? props.ticket : null),
                replaced_serial: item.replaced_serial ?? '',
                replaced_photo: null,
                replaced_photo_url: item.replaced_photo_url,
            },
            kept_photo_id: item.replaced_photo_id,
        }));
    }
    return props.firstItem ? [lineFrom(props.firstItem, { ...purposeLike(undefined), ticket: props.ticket })] : [];
};

// What the purchase brought, added to the lines (once each), for what it was bought for.
const purchaseLines = (): Line[] => {
    const p = props.purchase;
    const about = (): ItemPurposeValue => ({
        ...purposeLike(undefined),
        purpose: p?.purpose ?? '',
        purpose_other: p?.purpose_other ?? '',
        for_internal: p?.for_internal ?? false,
        customer_name: p?.customer_name ?? '',
        contract_id: p?.contract_id ?? null,
        ticket: p?.ticket ?? null,
        replaced_serial: p?.replaced_serial ?? '',
    });
    return (p?.items ?? []).map((option) => ({
        ...lineFrom(option, about()),
        checkout_type: 'issue',
        qty: option.lot ? option.qty : 1,
        purchase_request_id: option.purchase_request_id,
    }));
};

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
    borrower_phone: r?.borrower_phone ?? '',
    needed_by: r?.needed_by ?? '',
});

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
const ticketsUrl = props.servicesEnabled ? route('asset.requests.tickets') : null;

// "Same for every line": what this line is for, for whom and on which job (not its evidence).
const applyToAll = (from: Line) =>
    lines.value.forEach((line) => {
        if (line === from) return;
        line.about = { ...line.about, ...purposeLike(from.about), replaced_serial: line.about.replaced_serial, replaced_photo: line.about.replaced_photo, replaced_photo_url: line.about.replaced_photo_url };
    });

const getJson = async <T,>(url: string): Promise<T> => {
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error(String(response.status));
    return (await response.json()) as T;
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

// Nothing typed: what the company asks for most, shown when the box is entered (looked up once).
const frequent = ref<{ assets: CheckoutItemOption[]; parts: CheckoutItemOption[] } | null>(null);
const showFrequent = ref(false);
const openFrequent = async () => {
    if (itemSearch.value.trim() !== '' || full.value) return;
    showFrequent.value = true;
    if (frequent.value) return;
    try {
        frequent.value = await getJson(route('asset.requests.items'));
    } catch {
        frequent.value = { assets: [], parts: [] };
    }
};
// The list under the box: the search results, else the frequent items (when there are any).
const suggesting = computed(() => itemResults.value === null);
const shownItems = computed(() => {
    if (itemResults.value) return itemResults.value;
    const f = frequent.value;
    return showFrequent.value && f && f.assets.length + f.parts.length > 0 ? f : null;
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
                line.photo = option.photo;
            }
        } catch {
            // Leave it unknown: the server checks again on save.
        }
    });
});

// The purpose fields show their own messages; the rest are listed under the line.
const PURPOSE_FIELDS = ['purpose', 'purpose_other', 'customer_name', 'ticket_id', 'replaced_serial', 'replaced_photo'];
const lineErrors = (index: number) =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === `items.${index}` || (key.startsWith(`items.${index}.`) && !PURPOSE_FIELDS.includes(key.slice(`items.${index}.`.length))))
        .map(([, message]) => message);
const purposeErrors = (index: number): Record<string, string | undefined> =>
    Object.fromEntries(PURPOSE_FIELDS.map((field) => [field, (form.errors as Record<string, string>)[`items.${index}.${field}`]]));

// --- Save -----------------------------------------------------------------------------------
const save = (submit: boolean, thenPurchase: string | null = null) => {
    form.transform((data) => ({
        borrower_user_id: !props.can.forOthers ? me.id : borrowerKind.value === 'staff' ? data.borrower_user_id : null,
        borrower_name: props.can.forOthers && borrowerKind.value === 'other' ? data.borrower_name : null,
        borrower_phone: data.borrower_phone || null,
        needed_by: data.needed_by || null,
        submit,
        then_purchase: thenPurchase,
        // Files go as a form: a change is a POST that says PUT.
        ...(props.request ? { _method: 'put' } : {}),
        items: lines.value.map((line) => ({
            purpose: line.about.purpose || null,
            purpose_other: line.about.purpose === 'other' ? line.about.purpose_other || null : null,
            for_internal: line.about.for_internal,
            customer_name: line.about.for_internal ? null : line.about.customer_name || null,
            contract_id: line.about.for_internal ? null : line.about.contract_id,
            ticket_id: line.about.ticket?.id ?? null,
            replaced_serial: line.about.replaced_serial.trim() || null,
            replaced_photo: line.about.replaced_photo,
            // The photo the draft has, unless replaced or taken off.
            replaced_photo_id: !line.about.replaced_photo && line.about.replaced_photo_url ? line.kept_photo_id : null,
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
        form.post(route('asset.requests.update', props.request.ulid), { preserveScroll: true });
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
                    <Label for="borrower_phone">{{ t('requests.borrower_phone') }}</Label>
                    <Input id="borrower_phone" v-model="form.borrower_phone" type="tel" />
                    <InputError :message="form.errors.borrower_phone" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="needed_by">{{ t('requests.needed_by') }}</Label>
                    <Input id="needed_by" v-model="form.needed_by" type="date" :min="today" />
                    <InputError :message="form.errors.needed_by" />
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
                        @focus="openFrequent"
                        @click="openFrequent"
                    />
                    <p v-if="full" class="text-xs text-amber-700 dark:text-amber-400">{{ t('requests.lines_full', { max: maxItems }) }}</p>
                    <p v-if="searching" class="text-xs text-muted-foreground">{{ t('requests.searching') }}</p>
                    <div v-else-if="shownItems" class="max-h-96 overflow-y-auto rounded-md border">
                        <div v-if="suggesting" class="flex items-center justify-between gap-2 px-3 py-2 text-xs font-semibold">
                            {{ t('requests.frequent') }}
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-foreground"
                                :aria-label="t('requests.frequent_hide')"
                                @click="showFrequent = false"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <template v-for="group in ['assets', 'parts'] as const" :key="group">
                            <template v-if="shownItems[group].length">
                                <div class="bg-muted/50 px-3 py-1 text-xs font-medium">{{ t(`requests.groups.${group}`) }}</div>
                                <div
                                    v-for="option in shownItems[group]"
                                    :key="`${option.item_type}-${option.asset_id ?? option.part_id}`"
                                    class="flex items-center gap-3 border-t px-3 py-2 text-sm"
                                >
                                    <ListThumb :src="option.photo" :alt="option.name" />
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
                            v-if="!suggesting && shownItems.assets.length === 0 && shownItems.parts.length === 0"
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
                <p v-else class="text-xs text-muted-foreground">{{ t('item_purpose.copy_hint') }}</p>

                <div
                    v-for="(line, index) in lines"
                    :key="`${line.item_type}-${line.asset_id ?? line.part_id}`"
                    class="space-y-3 rounded-md border p-3"
                >
                    <div class="flex items-start gap-3">
                        <ListThumb :src="line.photo" :alt="line.name" />
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

                    <ItemPurposeFields
                        v-model="line.about"
                        :id="`line-${index}`"
                        :purposes="purposes"
                        :customers="customers"
                        :contracts="contracts"
                        :tickets-url="ticketsUrl"
                        :ticket-required="line.item_type === 'part'"
                        :errors="purposeErrors(index)"
                    />
                    <button
                        v-if="lines.length > 1"
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-primary underline-offset-4 hover:underline"
                        @click="applyToAll(line)"
                    >
                        <CopyCheck class="h-3 w-3" />
                        {{ t('item_purpose.apply_all') }}
                    </button>

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
