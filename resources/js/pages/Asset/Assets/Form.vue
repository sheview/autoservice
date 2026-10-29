<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import AttachmentPicker from '@/components/AttachmentPicker.vue';
import BarcodeScanButton from '@/components/BarcodeScanButton.vue';
import DuplicateMatches, { type DuplicateMatch } from '@/components/DuplicateMatches.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import QuickPicks from '@/components/QuickPicks.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDuplicates } from '@/composables/useDuplicates';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import { pendingPhotos } from '@/lib/photoUpload';
import { similarBrand, type BrandUse } from '@/lib/similarBrand';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChevronDown, Plus, X } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface SpecField {
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'select';
    options: string[];
    required: boolean;
}

interface Category {
    id: number;
    name: string;
    code_prefix: string;
    requires_serial: boolean;
    spec_fields: SpecField[];
}

interface AssetForm {
    ulid: string;
    asset_code: string;
    name: string;
    category_id: number;
    branch_id: number | null;
    customer_id: number | null;
    owner: 'company' | 'customer';
    brand: string | null;
    model: string | null;
    subtype: string | null;
    serials: string[];
    quantity: number;
    unit: string | null;
    property_no: string | null;
    status: string;
    location: string | null;
    storage_location_id: number | null;
    ip_address: string | null;
    mac_address: string | null;
    used_by: string | null;
    department: string | null;
    purchased_at: string | null;
    purchase_price: string | null;
    warranty_expires_at: string | null;
    notes: string | null;
    specs: Record<string, string | number> | unknown[];
}

const props = defineProps<{
    asset: AssetForm | null;
    // Creating from an asset of the same model: its fields without code, serials and equipment number.
    // Or from a received purchase request: only some fields.
    copy: Partial<Omit<AssetForm, 'ulid' | 'serials' | 'property_no'>> | null;
    purchase: { pr_no: string; quantity: number } | null;
    maxSerials: number;
    // Files already attached (editing).
    attachments: Attachment[];
    categories: Category[];
    // Storage locations (Settings) for the drop-down.
    locations: { id: number; name: string }[];
    branches: { id: number; name: string }[];
    customers: { id: number; code: string; name: string }[];
    statuses: string[];
    // Creating only: "issued" and "loaned", the asset is already out with someone.
    handedOutStatuses: string[];
    // Creating only: running contracts of each customer (by id) the user may put the new asset into.
    customerContracts: Record<string, { id: number; contract_no: string; title: string; starts_on: string; ends_on: string; covering: boolean }[]>;
    brands: BrandUse[];
    subtypes: string[];
    // The most used values, as buttons under their fields (sub-types per category).
    picks: { units: string[]; departments: string[]; subtypes: { category_id: number; name: string }[] };
}>();

const title = props.asset ? t('assets.edit') : t('assets.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('assets.title'), href: route('asset.assets.index') },
    ...(props.asset ? [{ title: props.asset.asset_code, href: route('asset.assets.show', props.asset.ulid) }] : []),
    { title, href: props.asset ? route('asset.assets.edit', props.asset.ulid) : route('asset.assets.create') },
];

// The asset being edited, or the one being copied.
const source = props.asset ?? props.copy;
// An empty JSON object arrives as [] from PHP.
const initialSpecs = source?.specs && !Array.isArray(source.specs) ? source.specs : {};

// A type alias (not an interface), so useForm accepts it as form data.
type SerialRow = { value: string };

const form = useForm({
    category_id: source?.category_id ?? null,
    asset_code: props.asset?.asset_code ?? '',
    name: source?.name ?? '',
    // Always picked (no "no branch" option), unless the user has only one branch to choose.
    branch_id: source?.branch_id ?? (props.branches.length === 1 ? props.branches[0].id : null),
    // Picked on purpose: the company itself or a customer (never left blank).
    owner: (source?.owner ?? (source?.customer_id ? 'customer' : props.customers.length ? '' : 'company')) as string,
    customer_id: source?.customer_id ?? null,
    brand: source?.brand ?? '',
    model: source?.model ?? '',
    subtype: source?.subtype ?? '',
    serials: (props.asset?.serials.length ? props.asset.serials : ['']).map((value) => ({ value })) as SerialRow[],
    quantity: source?.quantity ?? 1,
    unit: source?.unit ?? '',
    property_no: props.asset?.property_no ?? '',
    status: source?.status ?? 'in_use',
    holder_name: '',
    handed_out_on: '',
    location: source?.location ?? '',
    storage_location_id: source?.storage_location_id ?? (null as number | null),
    // A copy is another device: its own address, the same user and department are likely.
    ip_address: props.asset?.ip_address ?? '',
    mac_address: props.asset?.mac_address ?? '',
    used_by: source?.used_by ?? '',
    department: source?.department ?? '',
    purchased_at: source?.purchased_at ?? '',
    purchase_price: source?.purchase_price ?? '',
    warranty_expires_at: source?.warranty_expires_at ?? '',
    notes: source?.notes ?? '',
    specs: { ...initialSpecs } as Record<string, string | number>,
    // New files, sent with the form.
    attachments: [] as File[],
    // Creating only: contracts of the customer to put it into.
    contract_ids: [] as number[],
    // Creating only: saved although an asset of the same name, brand, model and owner is there.
    // Already given when adding another unit of a model on purpose (copy of an asset).
    confirm_duplicate: !!props.copy && !props.purchase,
});

// The same device already there (FindDuplicateAssets): equipment number, IP or MAC stop the save;
// the same name / brand / model / owner (a new asset) only asks to confirm.
type AssetDuplicate = {
    ulid: string;
    asset_code: string;
    name: string;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    reasons: string[];
    blocking: boolean;
    url: string | null;
    copy_url: string | null;
};
const duplicates = useDuplicates<AssetDuplicate>('asset.assets.duplicates', () => ({
    property_no: form.property_no,
    ip_address: form.ip_address,
    mac_address: form.mac_address,
    name: form.name,
    brand: form.brand,
    model: form.model,
    customer_id: form.owner === 'customer' ? form.customer_id : null,
    serials: form.serials.map((row) => row.value.trim()).filter((value) => value !== ''),
    except: props.asset?.ulid,
}));
const duplicateMatches = computed<DuplicateMatch[]>(() =>
    duplicates.value.map((asset) => ({
        key: asset.ulid,
        title: `${asset.asset_code} ${asset.name}`,
        detail: [asset.brand, asset.model, asset.serial_number].filter(Boolean).join(' · '),
        reasons: asset.reasons,
        blocking: asset.blocking,
        actions: [
            ...(asset.url ? [{ label: t('duplicates.open'), href: asset.url }] : []),
            ...(!asset.blocking && asset.copy_url ? [{ label: t('duplicates.copy'), href: asset.copy_url }] : []),
        ],
    })),
);
// Saving asks to confirm only "same model" (the server's check); the "may be the same" hints never stop it.
const onlyWarnings = computed(
    () => duplicateMatches.value.some((match) => match.reasons.includes('same_model')) && !duplicateMatches.value.some((match) => match.blocking),
);

// The chosen customer's contracts; the ones running today are ticked, so a new device is not left out of its MA.
const contractChoices = computed(() =>
    !props.asset && form.owner === 'customer' && form.customer_id ? (props.customerContracts[form.customer_id] ?? []) : [],
);
watch(contractChoices, (choices) => (form.contract_ids = choices.filter((c) => c.covering).map((c) => c.id)), { immediate: true });

type AssetFormData = ReturnType<typeof form.data>;

const category = computed(() => props.categories.find((c) => c.id === form.category_id) ?? null);
const requiresSerial = computed(() => category.value?.requires_serial ?? false);
const handedOut = computed(() => props.handedOutStatuses.includes(form.status));

const filledSerials = computed(() => form.serials.map((row) => row.value.trim()).filter((value) => value !== ''));

// A serial read by the camera: into the first empty row (a new row when all are filled), once.
const addScannedSerial = (serial: string) => {
    if (filledSerials.value.some((value) => value.toLowerCase() === serial.toLowerCase())) return;
    const empty = form.serials.find((row) => row.value.trim() === '');
    if (empty) {
        empty.value = serial;
    } else if (form.serials.length < props.maxSerials) {
        form.serials.push({ value: serial });
    }
};

// Spec fields the category requires stay in view; the others are folded with the other details.
const requiredSpecs = computed(() => category.value?.spec_fields.filter((field) => field.required) ?? []);
const optionalSpecs = computed(() => category.value?.spec_fields.filter((field) => !field.required) ?? []);

// "More details": folded on a phone when creating, so only what is needed is in view.
const moreOpen = ref(!!props.asset || (typeof window !== 'undefined' && window.matchMedia?.('(min-width: 768px)').matches));

// Photos picked before the asset exists (PhotoPicker): uploaded by its page once it is saved.
const photos = ref<File[]>([]);

const addSerial = () => {
    if (form.serials.length < props.maxSerials) {
        form.serials.push({ value: '' });
    }
};
const removeSerial = (index: number) => {
    form.serials.splice(index, 1);
    if (form.serials.length === 0) {
        addSerial();
    }
};

// Pasting several lines (e.g. a column copied from Excel) fills one row per line from here on.
const pasteSerials = (event: ClipboardEvent, index: number) => {
    const lines = (event.clipboardData?.getData('text') ?? '')
        .split(/\r?\n/)
        .map((line) => line.split('\t')[0].trim())
        .filter((line) => line !== '');
    if (lines.length < 2) {
        return; // a single value: let the browser paste it
    }
    event.preventDefault();
    lines.slice(0, props.maxSerials - index).forEach((serial, offset) => {
        const row = form.serials[index + offset] ?? (form.serials[index + offset] = { value: '' });
        row.value = serial;
    });
};

// Buttons under the fields: the brands used most, the sub-types of the chosen category.
const brandPicks = computed(() => [...props.brands].sort((a, b) => b.count - a.count).map((brand) => brand.name));
const subtypePicks = computed(() =>
    props.picks.subtypes.filter((subtype) => subtype.category_id === form.category_id).map((subtype) => subtype.name),
);

// A brand that looks like a more used one ("Ciso" for "Cisco"): offered, never forced.
const brandMatch = computed(() => (form.brand ? similarBrand(form.brand, props.brands) : null));
const brandIsNew = computed(() => form.brand.trim() !== '' && !props.brands.some((brand) => brand.name === form.brand.trim()));

// IPv4 (192.168.1.10) or IPv6 (fe80::1); the server checks the same with Laravel's "ip" rule.
const isIp = (value: string) =>
    /^((25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(25[0-5]|2[0-4]\d|1?\d?\d)$/.test(value) || (value.includes(':') && /^[0-9a-f:.]+$/i.test(value));
// Twelve hex digits, with or without separators: the server writes them as AA:BB:CC:DD:EE:FF.
const isMac = (value: string) => /^[0-9a-f]{12}$/i.test(value.replace(/[^0-9a-f]/gi, '')) && /^[0-9a-f:.\-\s]+$/i.test(value);

/**
 * The same rules as AssetRequest, checked before sending so the user is told at once.
 * Keys are the server's error keys, so both kinds of error show in the same place.
 */
function validate(): Record<string, string> {
    const errors: Record<string, string> = {};
    const required = (field: string) => t('assets.validation.required', { field: t(`assets.${field}`) });
    const choose = (field: string) => t('assets.validation.choose', { field: t(`assets.${field}`) });

    if (!form.category_id) errors.category_id = choose('category');
    if (form.name.trim() === '') errors.name = required('name');
    if (!form.status) errors.status = choose('status');
    if (!form.branch_id && form.location.trim() === '') errors.branch_id = t('assets.validation.place');
    if (!form.owner) errors.owner = choose('owner');
    else if (form.owner === 'customer' && !form.customer_id) errors.customer_id = t('assets.validation.customer');

    const seen = new Map<string, number>();
    form.serials
        .map((row) => row.value.trim())
        .filter((value) => value !== '')
        .forEach((serial, index) => {
            const key = serial.toLowerCase();
            if (seen.has(key)) errors[`serials.${index}`] = t('assets.validation.serial_duplicate', { serial });
            seen.set(key, index);
        });
    if (requiresSerial.value && filledSerials.value.length === 0) {
        errors.serials = t('assets.validation.serial_required', { category: category.value?.name ?? '' });
    }
    if (!requiresSerial.value) {
        const quantity = Number(form.quantity);
        if (!Number.isInteger(quantity) || quantity < 1) errors.quantity = t('assets.validation.quantity');
        else if (quantity < filledSerials.value.length) {
            errors.quantity = t('assets.validation.quantity_below_serials', { count: filledSerials.value.length });
        }
    }

    if (handedOut.value) {
        if (form.holder_name.trim() === '') errors.holder_name = t('assets.validation.handed_out');
        if (!form.handed_out_on) errors.handed_out_on = t('assets.validation.handed_out');
        else if (form.handed_out_on > new Date().toLocaleDateString('sv-SE')) errors.handed_out_on = t('assets.validation.handed_out_future');
    }

    if (form.ip_address.trim() && !isIp(form.ip_address.trim())) errors.ip_address = t('assets.validation.ip');
    if (form.mac_address.trim() && !isMac(form.mac_address.trim())) errors.mac_address = t('assets.validation.mac');

    if (form.purchased_at && !form.warranty_expires_at) errors.warranty_expires_at = t('assets.validation.warranty_required');
    else if (form.purchased_at && form.warranty_expires_at < form.purchased_at) {
        errors.warranty_expires_at = t('assets.validation.warranty_before_purchase');
    }

    category.value?.spec_fields.forEach((field) => {
        const value = form.specs[field.key];
        if (field.required && (value === undefined || value === null || String(value).trim() === '')) {
            errors[`specs.${field.key}`] = t('assets.validation.required', { field: field.label });
        }
    });

    return errors;
}

// The element of an error key, to scroll to: "serials.2" is the third serial row, "specs.cpu" its spec field.
const fieldId = (key: string) => {
    if (key === 'serials' || key.startsWith('serials.')) return `serial_${key.split('.')[1] ?? 0}`;
    if (key.startsWith('specs.')) return `spec_${key.slice(6)}`;
    if (key.startsWith('attachments')) return 'attachments';
    return key;
};

const showSummary = ref(false);

// Scroll to the first field with an error (in the order of the page) and put the cursor in it.
const focusFirstError = async () => {
    showSummary.value = true;
    // A field with an error may be in the folded details.
    moreOpen.value = true;
    await nextTick();
    const elements = Object.keys(form.errors)
        .map((key) => document.getElementById(fieldId(key)))
        .filter((element): element is HTMLElement => element !== null)
        .sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));
    const first = elements[0];
    if (first) {
        first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        first.focus({ preventScroll: true });
    } else {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
};

const submit = () => {
    form.clearErrors();
    showSummary.value = false;
    // Drop blank rows on screen too, so an error "serials.2" points at the third row shown.
    const kept = form.serials.filter((row) => row.value.trim() !== '');
    form.serials = kept.length ? kept : [{ value: '' }];

    const errors = validate();
    if (Object.keys(errors).length) {
        // Keys like "serials.0" are not form fields, but Inertia shows them the same way as the server's.
        form.setError(errors as Parameters<typeof form.setError>[0]);
        focusFirstError();
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: (page: { props: unknown }) => {
            form.reset('attachments');
            // A new asset: its page uploads the photos picked here (PhotoSlots pending-key).
            const id = (page.props as { asset?: { id: number } }).asset?.id;
            if (!props.asset && id && photos.value.length) pendingPhotos.value = { key: `asset:${id}`, files: photos.value };
        },
        onError: () => focusFirstError(),
    };
    // The quantity of a category with serials is counted by the server.
    const payload = (data: AssetFormData) => ({
        ...data,
        serials: data.serials.map((row) => row.value.trim()).filter((value) => value !== ''),
        customer_id: data.owner === 'customer' ? data.customer_id : null,
        contract_ids: props.asset ? [] : data.contract_ids.filter((id) => contractChoices.value.some((c) => c.id === id)),
        quantity: requiresSerial.value ? null : data.quantity,
        unit: requiresSerial.value ? null : data.unit,
    });

    if (props.asset) {
        // Files go as multipart, which PHP only reads on POST: send a POST that says PUT.
        form.transform((data) => ({ ...payload(data), _method: 'put' })).post(route('asset.assets.update', props.asset.ulid), options);
    } else {
        form.transform((data) => payload(data)).post(route('asset.assets.store'), options);
    }
};

const errors = computed(() => form.errors as Record<string, string>);
const specError = (key: string) => errors.value[`specs.${key}`];

// Location: one of the storage locations, or typed ("other", or a saved place that is not in the list).
const OTHER_LOCATION = '__other__';
const typingLocation = ref(form.location !== '' && !props.locations.some((location) => location.name === form.location));
const locationChoice = computed({
    get: () => form.location,
    set: (value: string) => {
        if (value === OTHER_LOCATION) {
            form.location = '';
            typingLocation.value = true;
        } else {
            form.location = value;
        }
    },
});
const pickLocation = () => {
    typingLocation.value = false;
    if (!props.locations.some((location) => location.name === form.location)) form.location = '';
};

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <p v-if="purchase" class="mb-6 rounded-md bg-muted/60 px-4 py-2 text-sm">
                {{ t('assets.from_purchase', { no: purchase.pr_no, count: purchase.quantity }) }}
            </p>
            <p v-else-if="copy" class="mb-6 rounded-md bg-muted/60 px-4 py-2 text-sm">{{ t('assets.copy_from', { code: copy.asset_code ?? '' }) }}</p>

            <p
                v-if="showSummary && form.hasErrors"
                role="alert"
                class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
            >
                {{ t('assets.fix_errors') }}
            </p>

            <form class="space-y-8" novalidate @submit.prevent="submit">
                <section class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('assets.general') }}</h3>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid content-start gap-2">
                            <Label for="category_id">{{ t('assets.category') }}<span class="text-red-600"> *</span></Label>
                            <select id="category_id" v-model="form.category_id" :class="selectClass">
                                <option :value="null" disabled>{{ t('assets.choose_category') }}</option>
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code_prefix }})</option>
                            </select>
                            <InputError :message="form.errors.category_id" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="name">{{ t('assets.name') }}<span class="text-red-600"> *</span></Label>
                            <Input id="name" v-model="form.name" autocomplete="off" />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="brand">{{ t('assets.brand') }}</Label>
                            <Input
                                id="brand"
                                v-model="form.brand"
                                list="brand_options"
                                :placeholder="t('assets.brand_placeholder')"
                                autocomplete="off"
                            />
                            <datalist id="brand_options">
                                <option v-for="brand in brands" :key="brand.name" :value="brand.name" />
                            </datalist>
                            <p v-if="brandMatch" class="flex flex-wrap items-center gap-2 text-xs text-amber-700 dark:text-amber-400">
                                {{ t('assets.brand_similar', { brand: form.brand.trim(), existing: brandMatch.name }) }}
                                <button type="button" class="font-medium underline" @click="form.brand = brandMatch.name">
                                    {{ t('assets.brand_use_existing', { existing: brandMatch.name }) }}
                                </button>
                            </p>
                            <p v-else-if="brandIsNew" class="text-xs text-muted-foreground">{{ t('assets.brand_new') }}</p>
                            <QuickPicks v-model="form.brand" :options="brandPicks" />
                            <InputError :message="form.errors.brand" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="model">{{ t('assets.model') }}</Label>
                            <Input id="model" v-model="form.model" />
                            <InputError :message="form.errors.model" />
                        </div>

                        <!-- Already there: open or copy it instead of typing it again -->
                        <DuplicateMatches v-if="duplicateMatches.length" class="sm:col-span-2" :matches="duplicateMatches" />

                        <div class="grid content-start gap-2">
                            <Label for="status">{{ t('assets.status') }}<span class="text-red-600"> *</span></Label>
                            <select id="status" v-model="form.status" :class="selectClass">
                                <option v-for="status in statuses" :key="status" :value="status">{{ t(`assets.statuses.${status}`) }}</option>
                                <option v-for="status in handedOutStatuses" :key="status" :value="status">
                                    {{ t(`assets.handed_out_statuses.${status}`) }}
                                </option>
                            </select>
                            <InputError :message="form.errors.status" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="owner">{{ t('assets.owner') }}<span class="text-red-600"> *</span></Label>
                            <select id="owner" v-model="form.owner" :class="selectClass">
                                <option value="" disabled>{{ t('assets.choose_owner') }}</option>
                                <option value="company">{{ t('assets.owner_company') }}</option>
                                <option v-if="customers.length" value="customer">{{ t('assets.customer') }}</option>
                            </select>
                            <InputError :message="form.errors.owner" />
                        </div>

                        <!-- Already out with someone: who has it and since when -->
                        <template v-if="handedOut">
                            <div class="grid content-start gap-2">
                                <Label for="holder_name">{{ t('assets.holder_name') }}<span class="text-red-600"> *</span></Label>
                                <Input id="holder_name" v-model="form.holder_name" autocomplete="off" />
                                <InputError :message="form.errors.holder_name" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label for="handed_out_on">{{ t('assets.handed_out_on') }}<span class="text-red-600"> *</span></Label>
                                <Input id="handed_out_on" v-model="form.handed_out_on" type="date" />
                                <InputError :message="form.errors.handed_out_on" />
                            </div>
                        </template>

                        <div v-if="form.owner === 'customer'" class="grid content-start gap-2" :class="{ 'sm:col-start-2': !handedOut }">
                            <Label for="customer_id">{{ t('assets.customer') }}<span class="text-red-600"> *</span></Label>
                            <select id="customer_id" v-model="form.customer_id" :class="selectClass">
                                <option :value="null" disabled>{{ t('assets.no_customer') }}</option>
                                <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                    {{ customer.name }} ({{ customer.code }})
                                </option>
                            </select>
                            <InputError :message="form.errors.customer_id" />
                        </div>

                        <div v-if="contractChoices.length" class="grid content-start gap-2 sm:col-span-2">
                            <Label>{{ t('contracts.add_new_to') }}</Label>
                            <p class="text-xs text-muted-foreground">{{ t('contracts.add_new_to_hint') }}</p>
                            <label v-for="c in contractChoices" :key="c.id" class="flex items-center gap-2 text-sm">
                                <input v-model="form.contract_ids" type="checkbox" :value="c.id" class="size-4 rounded border-input" />
                                <span class="font-mono text-xs">{{ c.contract_no }}</span>
                                <span>{{ c.title }}</span>
                                <span class="text-xs text-muted-foreground">{{ c.starts_on }} – {{ c.ends_on }}</span>
                            </label>
                            <InputError :message="form.errors.contract_ids" />
                        </div>

                        <div class="grid content-start gap-2 sm:col-start-1">
                            <Label for="branch_id">{{ t('assets.branch') }}</Label>
                            <select id="branch_id" v-model="form.branch_id" :class="selectClass">
                                <option :value="null" disabled>{{ t('assets.choose_branch') }}</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                            </select>
                            <p class="text-xs text-muted-foreground">{{ t('assets.place_hint') }}</p>
                            <InputError :message="form.errors.branch_id" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="location">{{ t('assets.location') }}</Label>
                            <!-- Picked from the storage locations (Settings), or "other" to type it (a name of the list is kept as that location) -->
                            <select v-if="locations.length && !typingLocation" id="location" v-model="locationChoice" :class="selectClass">
                                <option value="">{{ t('assets.location_choose') }}</option>
                                <option v-for="location in locations" :key="location.id" :value="location.name">{{ location.name }}</option>
                                <option :value="OTHER_LOCATION">{{ t('assets.location_other') }}</option>
                            </select>
                            <div v-else class="flex gap-2">
                                <Input
                                    id="location"
                                    v-model="form.location"
                                    class="min-w-0 flex-1"
                                    autocomplete="off"
                                    :placeholder="t('assets.location_placeholder')"
                                />
                                <Button v-if="locations.length" type="button" variant="outline" @click="pickLocation">{{
                                    t('assets.location_pick')
                                }}</Button>
                            </div>
                            <p class="text-xs text-muted-foreground">{{ t('assets.location_hint') }}</p>
                            <InputError :message="form.errors.location" />
                        </div>
                    </div>
                </section>

                <!-- Serial numbers, one row each (typed, pasted or scanned); the quantity is counted from them when the category requires them -->
                <section class="space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold">{{ t('assets.serials') }}<span v-if="requiresSerial" class="text-red-600"> *</span></h3>
                        <p class="text-xs text-muted-foreground">
                            {{ requiresSerial || !category ? t('assets.serials_hint') : t('assets.serials_optional_hint') }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <div v-for="(row, index) in form.serials" :key="index" class="flex items-start gap-2">
                            <span class="w-6 shrink-0 pt-2.5 text-right text-xs text-muted-foreground">{{ index + 1 }}.</span>
                            <div class="min-w-0 flex-1">
                                <Input
                                    :id="`serial_${index}`"
                                    v-model="row.value"
                                    class="font-mono"
                                    autocomplete="off"
                                    :aria-label="`${t('assets.serial_number')} ${index + 1}`"
                                    @paste="pasteSerials($event, index)"
                                />
                                <InputError :message="errors[`serials.${index}`]" />
                            </div>
                            <Button
                                v-if="form.serials.length > 1"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-11 shrink-0 sm:size-9"
                                :aria-label="t('assets.remove_serial')"
                                @click="removeSerial(index)"
                            >
                                <X class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                    <InputError :message="errors.serials" />
                    <div class="flex flex-wrap items-center gap-3">
                        <!-- One device after another: each code read goes into the next empty row -->
                        <BarcodeScanButton
                            continuous
                            :label="t('scan_camera.serials')"
                            :hint="t('scan_camera.hint_serials')"
                            @scanned="addScannedSerial"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            :disabled="form.serials.length >= maxSerials"
                            @click="addSerial"
                        >
                            <Plus class="h-4 w-4" />
                            {{ t('assets.add_serial') }}
                        </Button>
                        <span v-if="requiresSerial" class="text-sm text-muted-foreground">
                            {{ t('assets.quantity_from_serials', { count: filledSerials.length }) }}
                        </span>
                    </div>

                    <!-- Things without serial (cables, connectors, batteries...): one record of a lot, with quantity and unit -->
                    <div v-if="category && !requiresSerial" class="grid grid-cols-2 gap-6 pt-2 sm:grid-cols-4">
                        <div class="grid content-start gap-2">
                            <Label for="quantity">{{ t('assets.quantity') }}<span class="text-red-600"> *</span></Label>
                            <Input id="quantity" v-model.number="form.quantity" type="number" inputmode="numeric" min="1" step="1" />
                            <InputError :message="form.errors.quantity" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="unit">{{ t('assets.unit') }}</Label>
                            <Input id="unit" v-model="form.unit" :placeholder="t('assets.unit_placeholder')" autocomplete="off" />
                            <QuickPicks v-model="form.unit" :options="picks.units" />
                            <InputError :message="form.errors.unit" />
                        </div>
                    </div>
                </section>

                <!-- Spec fields the category requires: needed to save, so not folded away -->
                <section v-if="requiredSpecs.length" class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('assets.specs') }}</h3>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div v-for="field in requiredSpecs" :key="field.key" class="grid content-start gap-2">
                            <Label :for="`spec_${field.key}`"> {{ field.label }}<span class="text-red-600"> *</span> </Label>
                            <select v-if="field.type === 'select'" :id="`spec_${field.key}`" v-model="form.specs[field.key]" :class="selectClass">
                                <option :value="undefined">{{ t('common.none') }}</option>
                                <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
                            </select>
                            <Input
                                v-else
                                :id="`spec_${field.key}`"
                                v-model="form.specs[field.key]"
                                :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
                                :step="field.type === 'number' ? 'any' : undefined"
                            />
                            <InputError :message="specError(field.key)" />
                        </div>
                    </div>
                </section>

                <!-- Everything else, folded on a phone when creating (open when editing or on a wide screen) -->
                <details class="group rounded-lg border" :open="moreOpen" @toggle="moreOpen = ($event.target as HTMLDetailsElement).open">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 px-4 py-2 text-sm font-semibold">
                        <span>
                            {{ t('assets.more_details') }}
                            <span class="block text-xs font-normal text-muted-foreground">{{ t('assets.more_details_hint') }}</span>
                        </span>
                        <ChevronDown class="size-4 shrink-0 transition-transform group-open:rotate-180" />
                    </summary>
                    <div class="space-y-8 border-t p-4">
                        <section class="space-y-4">
                            <div class="grid gap-6 sm:grid-cols-2">
                                <div class="grid content-start gap-2">
                                    <Label for="asset_code">{{ t('assets.asset_code') }}</Label>
                                    <Input
                                        id="asset_code"
                                        v-model="form.asset_code"
                                        class="font-mono"
                                        :placeholder="category ? `${category.code_prefix}-00001` : ''"
                                        autocomplete="off"
                                    />
                                    <p class="text-xs text-muted-foreground">{{ t('assets.asset_code_hint') }}</p>
                                    <InputError :message="form.errors.asset_code" />
                                </div>

                                <div class="grid content-start gap-2">
                                    <Label for="property_no">{{ t('assets.property_no') }}</Label>
                                    <div class="flex gap-2">
                                        <Input id="property_no" v-model="form.property_no" class="font-mono" autocomplete="off" />
                                        <BarcodeScanButton
                                            :hint="t('scan_camera.hint_field', { field: t('assets.property_no') })"
                                            @scanned="(text) => (form.property_no = text)"
                                        />
                                    </div>
                                    <InputError :message="form.errors.property_no" />
                                </div>

                                <div class="grid content-start gap-2">
                                    <Label for="subtype">{{ t('assets.subtype') }}</Label>
                                    <Input
                                        id="subtype"
                                        v-model="form.subtype"
                                        list="subtype_options"
                                        :placeholder="t('assets.subtype_placeholder')"
                                        autocomplete="off"
                                    />
                                    <datalist id="subtype_options">
                                        <option v-for="subtype in subtypes" :key="subtype" :value="subtype" />
                                    </datalist>
                                    <QuickPicks v-model="form.subtype" :options="subtypePicks" />
                                    <InputError :message="form.errors.subtype" />
                                </div>
                            </div>
                        </section>

                        <!-- Who uses the device and its network address: filled into the job sheet of a ticket -->
                        <section class="space-y-4">
                            <div>
                                <h3 class="text-sm font-semibold">{{ t('assets.usage') }}</h3>
                                <p class="text-xs text-muted-foreground">{{ t('assets.usage_hint') }}</p>
                            </div>
                            <div class="grid gap-6 sm:grid-cols-2">
                                <div class="grid content-start gap-2">
                                    <Label for="used_by">{{ t('assets.used_by') }}</Label>
                                    <Input id="used_by" v-model="form.used_by" autocomplete="off" />
                                    <InputError :message="form.errors.used_by" />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="department">{{ t('assets.department') }}</Label>
                                    <Input id="department" v-model="form.department" autocomplete="off" />
                                    <QuickPicks v-model="form.department" :options="picks.departments" />
                                    <InputError :message="form.errors.department" />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="ip_address">{{ t('assets.ip_address') }}</Label>
                                    <Input
                                        id="ip_address"
                                        v-model="form.ip_address"
                                        class="font-mono"
                                        placeholder="192.168.1.10"
                                        autocomplete="off"
                                    />
                                    <InputError :message="form.errors.ip_address" />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="mac_address">{{ t('assets.mac_address') }}</Label>
                                    <div class="flex gap-2">
                                        <Input
                                            id="mac_address"
                                            v-model="form.mac_address"
                                            class="font-mono"
                                            placeholder="AA:BB:CC:DD:EE:FF"
                                            autocomplete="off"
                                        />
                                        <BarcodeScanButton
                                            :hint="t('scan_camera.hint_field', { field: t('assets.mac_address') })"
                                            @scanned="(text) => (form.mac_address = text)"
                                        />
                                    </div>
                                    <InputError :message="form.errors.mac_address" />
                                </div>
                            </div>
                        </section>

                        <section v-if="optionalSpecs.length" class="space-y-4">
                            <h3 class="text-sm font-semibold">{{ t('assets.specs') }}</h3>
                            <div class="grid gap-6 sm:grid-cols-2">
                                <div v-for="field in optionalSpecs" :key="field.key" class="grid content-start gap-2">
                                    <Label :for="`spec_${field.key}`">
                                        {{ field.label }}<span v-if="field.required" class="text-red-600"> *</span>
                                    </Label>
                                    <select
                                        v-if="field.type === 'select'"
                                        :id="`spec_${field.key}`"
                                        v-model="form.specs[field.key]"
                                        :class="selectClass"
                                    >
                                        <option :value="undefined">{{ t('common.none') }}</option>
                                        <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
                                    </select>
                                    <Input
                                        v-else
                                        :id="`spec_${field.key}`"
                                        v-model="form.specs[field.key]"
                                        :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
                                        :step="field.type === 'number' ? 'any' : undefined"
                                    />
                                    <InputError :message="specError(field.key)" />
                                </div>
                            </div>
                        </section>

                        <section class="space-y-4">
                            <h3 class="text-sm font-semibold">{{ t('assets.purchase') }}</h3>
                            <div class="grid gap-6 sm:grid-cols-3">
                                <div class="grid content-start gap-2">
                                    <Label for="purchased_at">{{ t('assets.purchased_at') }}</Label>
                                    <Input id="purchased_at" v-model="form.purchased_at" type="date" />
                                    <InputError :message="form.errors.purchased_at" />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="purchase_price">{{ t('assets.purchase_price') }}</Label>
                                    <Input id="purchase_price" v-model="form.purchase_price" type="number" min="0" step="0.01" />
                                    <InputError :message="form.errors.purchase_price" />
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label for="warranty_expires_at">
                                        {{ t('assets.warranty_expires_at') }}<span v-if="form.purchased_at" class="text-red-600"> *</span>
                                    </Label>
                                    <Input
                                        id="warranty_expires_at"
                                        v-model="form.warranty_expires_at"
                                        type="date"
                                        :min="form.purchased_at || undefined"
                                    />
                                    <InputError :message="form.errors.warranty_expires_at" />
                                </div>
                            </div>
                        </section>

                        <div class="grid content-start gap-2">
                            <Label for="notes">{{ t('assets.notes') }}</Label>
                            <textarea
                                id="notes"
                                v-model="form.notes"
                                rows="3"
                                class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                            />
                            <InputError :message="form.errors.notes" />
                        </div>

                        <section id="attachments" class="space-y-2">
                            <AttachmentList v-if="asset" :attachments="attachments" can-delete />
                            <h3 v-else class="text-sm font-semibold">{{ t('attachments.title') }}</h3>
                            <AttachmentPicker v-model="form.attachments" :errors="errors" />
                        </section>
                    </div>
                </details>

                <!-- Creating only: photos last, taken once the form is filled in; uploaded once the asset is saved -->
                <section v-if="!asset" class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('photos.title') }}</h3>
                    <PhotoPicker v-model="photos" />
                </section>

                <!-- Before saving: a same-model warning (shown by the name) is passed by confirming -->
                <div v-if="(!asset && onlyWarnings) || errors.duplicate" class="space-y-2">
                    <label v-if="!asset && (onlyWarnings || errors.duplicate)" class="flex min-h-11 items-center gap-2 text-sm">
                        <input v-model="form.confirm_duplicate" type="checkbox" class="size-5 rounded border-input" />
                        {{ t('duplicates.confirm') }}
                    </label>
                    <InputError :message="errors.duplicate" />
                </div>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="asset ? route('asset.assets.show', asset.ulid) : route('asset.assets.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
