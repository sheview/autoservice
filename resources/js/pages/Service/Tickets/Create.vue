<script setup lang="ts">
import AttachmentPicker from '@/components/AttachmentPicker.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TicketTitlePicker from '@/components/TicketTitlePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface AssetOption {
    id: number;
    ulid: string;
    asset_code: string;
    name: string;
    category: string | null;
    branch: string | null;
    customer_id: number | null;
    // Who uses it day to day: offered as the person reporting.
    used_by: string | null;
}

interface CoveringContract {
    id: number;
    contract_no: string;
    title: string;
    service_window: string;
    slas: Record<string, { response_minutes: number; resolve_minutes: number }>;
}

interface ContactPerson {
    id: number;
    name: string;
    phone: string | null;
    position: string | null;
}

const props = defineProps<{
    preset: { asset: AssetOption | null; customer_id: number | null };
    customerAccount: boolean;
    customers: { id: number; code: string; name: string }[];
    assetOptions: AssetOption[];
    contracts: CoveringContract[];
    assignees: { id: number; name: string }[];
    contactPeople: ContactPerson[];
    priorities: string[];
    sources: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('tickets.title'), href: route('service.tickets.index') },
    { title: t('tickets.create'), href: route('service.tickets.create') },
];

const form = useForm({
    customer_id: props.preset.customer_id,
    asset_id: props.preset.asset?.id ?? null,
    contract_id: props.contracts[0]?.id ?? null,
    title: '',
    description: '',
    priority: 'medium',
    source: 'phone',
    contact_name: '',
    contact_phone: '',
    assignee_id: null as number | null,
    // A device not in the system, described by hand (ignored when an asset is picked).
    device_name: '',
    device_brand: '',
    device_model: '',
    device_serial: '',
    device_serial_unknown: false,
    device_location: '',
    device_ip: '',
    attachments: [] as File[],
});

const selectedAsset = ref<AssetOption | null>(props.preset.asset);

// The person reporting: someone with an account (the customer's, or our staff), or a name typed by hand.
const contactMode = ref<'system' | 'manual'>(props.contactPeople.length ? 'system' : 'manual');
const contactId = ref<number | null>(null);
const pickContact = () => {
    const person = props.contactPeople.find((p) => p.id === contactId.value);
    form.contact_name = person?.name ?? '';
    form.contact_phone = person?.phone ?? '';
};
// Only when the user flips it: offering the asset's user also sets the mode, and must keep the name.
const clearContact = () => {
    contactId.value = null;
    form.contact_name = '';
    form.contact_phone = '';
};
// Another customer means other people: a picked person who is no longer listed is let go.
watch(
    () => props.contactPeople,
    (people) => {
        if (contactMode.value === 'system' && contactId.value && !people.some((p) => p.id === contactId.value)) {
            contactId.value = null;
            pickContact();
        }
        if (!people.length) contactMode.value = 'manual';
    },
);
// Is the device a registered asset, or one described by hand?
const deviceMode = ref<'registered' | 'unregistered'>('registered');
watch(deviceMode, (mode) => {
    if (mode === 'unregistered' && selectedAsset.value) clearAsset();
});

// The regular user of the asset is most likely the one reporting: offered, never overwriting what was typed.
const offerContact = (asset: AssetOption | null) => {
    if (asset?.used_by && form.contact_name.trim() === '') {
        form.contact_name = asset.used_by;
        contactId.value = props.contactPeople.find((p) => p.name === asset.used_by)?.id ?? null;
        contactMode.value = contactId.value ? 'system' : 'manual';
    }
};
offerContact(props.preset.asset);
const assetSearch = ref('');

// Ask the server for the assets / covering contracts that match what is filled in so far.
const reload = (only: string[]) =>
    router.reload({
        only,
        data: { customer_id: form.customer_id ?? undefined, asset_id: form.asset_id ?? undefined, asset_search: assetSearch.value || undefined },
        replace: true,
    });

let timer: ReturnType<typeof setTimeout> | undefined;
watch(assetSearch, () => {
    clearTimeout(timer);
    timer = setTimeout(() => reload(['assetOptions']), 300);
});

watch(
    () => form.customer_id,
    () => {
        if (selectedAsset.value && selectedAsset.value.customer_id !== form.customer_id) {
            selectedAsset.value = null;
            form.asset_id = null;
        }
        reload(['contracts', 'assetOptions', 'contactPeople']);
    },
);

const pickAsset = (asset: AssetOption) => {
    selectedAsset.value = asset;
    form.asset_id = asset.id;
    if (asset.customer_id) form.customer_id = asset.customer_id;
    offerContact(asset);
    assetSearch.value = '';
    reload(['contracts']);
};

const clearAsset = () => {
    selectedAsset.value = null;
    form.asset_id = null;
    reload(['contracts']);
};

// Keep the chosen contract valid when the list changes; the first covering one by default.
watch(
    () => props.contracts,
    (contracts) => {
        if (!contracts.some((c) => c.id === form.contract_id)) form.contract_id = contracts[0]?.id ?? null;
    },
);

const contract = computed(() => props.contracts.find((c) => c.id === form.contract_id) ?? null);
const sla = computed(() => contract.value?.slas[form.priority] ?? null);
const hours = (minutes: number) => t('contracts.hours', { hours: minutes / 60 });

const submit = () => form.post(route('service.tickets.store'), { preserveScroll: true });

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('tickets.create')" />

        <div class="p-4">
            <Heading :title="t('tickets.create')" />

            <form class="space-y-8" @submit.prevent="submit">
                <section class="grid gap-6 sm:grid-cols-2">
                    <div v-if="customers.length && !customerAccount" class="grid content-start gap-2 sm:col-span-2">
                        <Label for="customer_id">{{ t('tickets.customer') }}</Label>
                        <select id="customer_id" v-model="form.customer_id" :class="selectClass">
                            <option :value="null">{{ t('tickets.no_customer') }}</option>
                            <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                {{ customer.name }} ({{ customer.code }})
                            </option>
                        </select>
                        <InputError :message="form.errors.customer_id" />
                    </div>

                    <fieldset class="grid content-start gap-2 sm:col-span-2">
                        <legend class="mb-2 text-sm font-medium">{{ t('tickets.device_mode') }}</legend>
                        <div class="flex flex-wrap gap-6">
                            <label v-for="mode in ['registered', 'unregistered'] as const" :key="mode" class="flex items-start gap-2 text-sm">
                                <input v-model="deviceMode" type="radio" :value="mode" class="mt-0.5 size-4" />
                                <span>
                                    {{ t(`tickets.device_modes.${mode}`) }}
                                    <span class="block text-xs text-muted-foreground">{{ t(`tickets.device_modes_hint.${mode}`) }}</span>
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- A device not in the system: what it is, as told -->
                    <div v-if="deviceMode === 'unregistered'" class="grid gap-6 rounded-md border p-4 sm:col-span-2 sm:grid-cols-2">
                        <div class="grid content-start gap-2 sm:col-span-2">
                            <Label for="device_name">{{ t('tickets.device_name') }} <span class="text-red-600">*</span></Label>
                            <Input id="device_name" v-model="form.device_name" :placeholder="t('tickets.device_name_placeholder')" />
                            <InputError :message="form.errors.device_name" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="device_brand">{{ t('tickets.device_brand') }} <span class="text-red-600">*</span></Label>
                            <Input id="device_brand" v-model="form.device_brand" />
                            <InputError :message="form.errors.device_brand" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="device_model">{{ t('tickets.device_model') }} <span class="text-red-600">*</span></Label>
                            <Input id="device_model" v-model="form.device_model" />
                            <InputError :message="form.errors.device_model" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="device_serial">
                                {{ t('tickets.device_serial') }} <span v-if="!form.device_serial_unknown" class="text-red-600">*</span>
                            </Label>
                            <Input id="device_serial" v-model="form.device_serial" class="font-mono" :disabled="form.device_serial_unknown" />
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="form.device_serial_unknown" type="checkbox" class="size-4 rounded border-input" />
                                {{ t('tickets.device_serial_unknown') }}
                            </label>
                            <InputError :message="form.errors.device_serial" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="device_ip">{{ t('tickets.device_ip') }}</Label>
                            <Input id="device_ip" v-model="form.device_ip" class="font-mono" placeholder="192.168.1.10" />
                            <InputError :message="form.errors.device_ip" />
                        </div>
                        <div class="grid content-start gap-2 sm:col-span-2">
                            <Label for="device_location">{{ t('tickets.device_location') }}</Label>
                            <Input id="device_location" v-model="form.device_location" />
                            <InputError :message="form.errors.device_location" />
                        </div>
                    </div>

                    <div v-else class="grid content-start gap-2 sm:col-span-2">
                        <Label for="asset_search">{{ t('tickets.asset') }}</Label>
                        <div v-if="selectedAsset" class="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                            <span>
                                <span class="font-mono text-xs">{{ selectedAsset.asset_code }}</span>
                                <span class="ml-2">{{ selectedAsset.name }}</span>
                                <span class="ml-2 text-xs text-muted-foreground">{{
                                    [selectedAsset.category, selectedAsset.branch].filter(Boolean).join(' · ')
                                }}</span>
                            </span>
                            <button type="button" class="text-xs text-primary underline-offset-4 hover:underline" @click="clearAsset">
                                {{ t('tickets.change_asset') }}
                            </button>
                        </div>
                        <template v-else>
                            <Input
                                id="asset_search"
                                v-model="assetSearch"
                                type="search"
                                :placeholder="t('tickets.asset_search_placeholder')"
                                autocomplete="off"
                            />
                            <ul v-if="assetSearch && assetOptions.length" class="max-h-56 divide-y overflow-y-auto rounded-md border text-sm">
                                <li v-for="asset in assetOptions" :key="asset.id">
                                    <button type="button" class="w-full px-3 py-1.5 text-left hover:bg-muted" @click="pickAsset(asset)">
                                        <span class="font-mono text-xs">{{ asset.asset_code }}</span>
                                        <span class="ml-2">{{ asset.name }}</span>
                                        <span class="ml-2 text-xs text-muted-foreground">{{
                                            [asset.category, asset.branch].filter(Boolean).join(' · ')
                                        }}</span>
                                    </button>
                                </li>
                            </ul>
                            <p v-else-if="assetSearch" class="text-xs text-muted-foreground">{{ t('common.no_results') }}</p>
                        </template>
                        <InputError :message="form.errors.asset_id" />
                    </div>

                    <div v-if="!customerAccount" class="grid content-start gap-2 sm:col-span-2">
                        <Label for="contract_id">{{ t('tickets.contract') }}</Label>
                        <select v-if="contracts.length" id="contract_id" v-model="form.contract_id" :class="selectClass">
                            <option v-for="c in contracts" :key="c.id" :value="c.id">
                                {{ c.contract_no }} — {{ c.title }} ({{ c.service_window }})
                            </option>
                            <option :value="null">{{ t('tickets.out_of_contract') }}</option>
                        </select>
                        <p v-if="!contract" class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                            {{ t('tickets.out_of_contract_hint') }}
                        </p>
                        <InputError :message="form.errors.contract_id" />
                    </div>
                </section>

                <section class="grid gap-6 sm:grid-cols-2">
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="title">{{ t('tickets.title_field') }}</Label>
                        <TicketTitlePicker v-model="form.title" />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="description">{{ t('tickets.description_field') }}</Label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="4"
                            class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="priority">{{ t('tickets.priority') }}</Label>
                        <select id="priority" v-model="form.priority" :class="selectClass">
                            <option v-for="priority in priorities" :key="priority" :value="priority">
                                {{ t(`tickets.priorities.${priority}`) }}
                            </option>
                        </select>
                        <p v-if="contract" class="text-xs text-muted-foreground">
                            <template v-if="sla">
                                {{ t('tickets.sla_response') }} {{ hours(sla.response_minutes) }} · {{ t('tickets.sla_resolve') }}
                                {{ hours(sla.resolve_minutes) }} ({{ contract.service_window }})
                            </template>
                            <template v-else>{{ t('tickets.sla_states.none') }}</template>
                        </p>
                        <InputError :message="form.errors.priority" />
                    </div>

                    <div v-if="!customerAccount" class="grid content-start gap-2">
                        <Label for="source">{{ t('tickets.source') }}</Label>
                        <select id="source" v-model="form.source" :class="selectClass">
                            <option v-for="source in sources" :key="source" :value="source">{{ t(`tickets.sources.${source}`) }}</option>
                        </select>
                        <InputError :message="form.errors.source" />
                    </div>

                    <div class="grid content-start gap-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <Label for="contact_name">{{ t('tickets.contact_name') }}</Label>
                            <div v-if="contactPeople.length" class="flex gap-4 text-xs">
                                <label v-for="mode in ['system', 'manual'] as const" :key="mode" class="flex items-center gap-1.5">
                                    <input v-model="contactMode" type="radio" :value="mode" class="size-3.5" @change="clearContact" />
                                    {{ t(`tickets.contact_modes.${mode}`) }}
                                </label>
                            </div>
                        </div>
                        <select v-if="contactMode === 'system'" id="contact_name" v-model="contactId" :class="selectClass" @change="pickContact">
                            <option :value="null">{{ t('tickets.contact_pick') }}</option>
                            <option v-for="person in contactPeople" :key="person.id" :value="person.id">
                                {{ person.name }}{{ person.position ? ` (${person.position})` : '' }}
                            </option>
                        </select>
                        <Input v-else id="contact_name" v-model="form.contact_name" />
                        <InputError :message="form.errors.contact_name" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="contact_phone">{{ t('tickets.contact_phone') }}</Label>
                        <Input id="contact_phone" v-model="form.contact_phone" type="tel" />
                        <InputError :message="form.errors.contact_phone" />
                    </div>

                    <div v-if="assignees.length" class="grid content-start gap-2 sm:col-span-2">
                        <Label for="assignee_id">{{ t('tickets.assignee') }}</Label>
                        <select id="assignee_id" v-model="form.assignee_id" :class="selectClass">
                            <option :value="null">{{ t('tickets.unassigned') }}</option>
                            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <InputError :message="form.errors.assignee_id" />
                    </div>

                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label>{{ t('attachments.title') }}</Label>
                        <AttachmentPicker v-model="form.attachments" :errors="form.errors" />
                    </div>
                </section>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('tickets.create') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('service.tickets.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
