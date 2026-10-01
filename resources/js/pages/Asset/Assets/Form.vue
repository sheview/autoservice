<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import AttachmentPicker from '@/components/AttachmentPicker.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import { similarBrand, type BrandUse } from '@/lib/similarBrand';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, X } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

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
    branches: { id: number; name: string }[];
    customers: { id: number; code: string; name: string }[];
    statuses: string[];
    // Creating only: "issued" and "loaned", the asset is already out with someone.
    handedOutStatuses: string[];
    brands: BrandUse[];
    subtypes: string[];
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
});

type AssetFormData = ReturnType<typeof form.data>;

const category = computed(() => props.categories.find((c) => c.id === form.category_id) ?? null);
const requiresSerial = computed(() => category.value?.requires_serial ?? false);
const handedOut = computed(() => props.handedOutStatuses.includes(form.status));

const filledSerials = computed(() => form.serials.map((row) => row.value.trim()).filter((value) => value !== ''));

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

    const options = { preserveScroll: true, onSuccess: () => form.reset('attachments'), onError: () => focusFirstError() };
    // The quantity of a category with serials is counted by the server.
    const payload = (data: AssetFormData) => ({
        ...data,
        serials: data.serials.map((row) => row.value.trim()).filter((value) => value !== ''),
        customer_id: data.owner === 'customer' ? data.customer_id : null,
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

                        <div class="grid content-start gap-2 sm:col-span-2">
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
                            <InputError :message="form.errors.brand" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="model">{{ t('assets.model') }}</Label>
                            <Input id="model" v-model="form.model" />
                            <InputError :message="form.errors.model" />
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
                            <InputError :message="form.errors.subtype" />
                        </div>

                        <div class="grid content-start gap-2">
                            <Label for="property_no">{{ t('assets.property_no') }}</Label>
                            <Input id="property_no" v-model="form.property_no" class="font-mono" autocomplete="off" />
                            <InputError :message="form.errors.property_no" />
                        </div>

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
                            <Input id="location" v-model="form.location" />
                            <InputError :message="form.errors.location" />
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
                            <InputError :message="form.errors.department" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="ip_address">{{ t('assets.ip_address') }}</Label>
                            <Input id="ip_address" v-model="form.ip_address" class="font-mono" placeholder="192.168.1.10" autocomplete="off" />
                            <InputError :message="form.errors.ip_address" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="mac_address">{{ t('assets.mac_address') }}</Label>
                            <Input id="mac_address" v-model="form.mac_address" class="font-mono" placeholder="AA:BB:CC:DD:EE:FF" autocomplete="off" />
                            <InputError :message="form.errors.mac_address" />
                        </div>
                    </div>
                </section>

                <!-- Serial numbers, one row each; the quantity is counted from them when the category requires them -->
                <section class="space-y-3">
                    <div>
                        <h3 class="text-sm font-semibold">{{ t('assets.serials') }}<span v-if="requiresSerial" class="text-red-600"> *</span></h3>
                        <p class="text-xs text-muted-foreground">
                            {{ requiresSerial || !category ? t('assets.serials_hint') : t('assets.serials_optional_hint') }}
                        </p>
                    </div>
                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr>
                                    <th class="w-10 px-3 py-2 font-medium">#</th>
                                    <th class="px-3 py-2 font-medium">{{ t('assets.serial_number') }}</th>
                                    <th class="w-10 px-3 py-2">
                                        <span class="sr-only">{{ t('common.actions') }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in form.serials" :key="index" class="border-t align-top">
                                    <td class="px-3 py-2 pt-4 text-muted-foreground">{{ index + 1 }}</td>
                                    <td class="px-3 py-2">
                                        <Input
                                            :id="`serial_${index}`"
                                            v-model="row.value"
                                            class="font-mono"
                                            autocomplete="off"
                                            :aria-label="`${t('assets.serial_number')} ${index + 1}`"
                                            @paste="pasteSerials($event, index)"
                                        />
                                        <InputError :message="errors[`serials.${index}`]" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <Button
                                            v-if="form.serials.length > 1"
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            :aria-label="t('assets.remove_serial')"
                                            @click="removeSerial(index)"
                                        >
                                            <X class="h-4 w-4" />
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <InputError :message="errors.serials" />
                    <div class="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="outline" size="sm" :disabled="form.serials.length >= maxSerials" @click="addSerial">
                            <Plus class="h-4 w-4" />
                            {{ t('assets.add_serial') }}
                        </Button>
                        <span v-if="requiresSerial" class="text-sm text-muted-foreground">
                            {{ t('assets.quantity_from_serials', { count: filledSerials.length }) }}
                        </span>
                    </div>

                    <!-- Things without serial (cables, connectors, batteries...): one record of a lot, with quantity and unit -->
                    <div v-if="category && !requiresSerial" class="grid gap-6 pt-2 sm:grid-cols-4">
                        <div class="grid content-start gap-2">
                            <Label for="quantity">{{ t('assets.quantity') }}<span class="text-red-600"> *</span></Label>
                            <Input id="quantity" v-model.number="form.quantity" type="number" min="1" step="1" />
                            <InputError :message="form.errors.quantity" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="unit">{{ t('assets.unit') }}</Label>
                            <Input id="unit" v-model="form.unit" :placeholder="t('assets.unit_placeholder')" autocomplete="off" />
                            <InputError :message="form.errors.unit" />
                        </div>
                    </div>
                </section>

                <section class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('assets.specs') }}</h3>
                    <p v-if="!category || category.spec_fields.length === 0" class="text-sm text-muted-foreground">
                        {{ category ? t('assets.no_specs') : t('assets.choose_category') }}
                    </p>
                    <div v-else class="grid gap-6 sm:grid-cols-2">
                        <div v-for="field in category.spec_fields" :key="field.key" class="grid content-start gap-2">
                            <Label :for="`spec_${field.key}`"> {{ field.label }}<span v-if="field.required" class="text-red-600"> *</span> </Label>
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
                            <Input id="warranty_expires_at" v-model="form.warranty_expires_at" type="date" :min="form.purchased_at || undefined" />
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
