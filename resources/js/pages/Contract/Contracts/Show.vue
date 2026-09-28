<script setup lang="ts">
import ContractPhaseBadge from '@/components/ContractPhaseBadge.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface AssetRow {
    id: number;
    ulid: string;
    asset_code: string;
    name: string;
    category: string | null;
    branch: string | null;
    status: string;
    serial_number: string | null;
}

interface ContractDetail {
    id: number;
    contract_no: string;
    title: string;
    status: string;
    phase: string;
    service_window: string;
    pm_interval_months: number | null;
    notify_days_before: number;
    notes: string | null;
    customer: { id: number; code: string; name: string; contact_name: string | null; phone: string | null; email: string | null } | null;
    starts_on: string;
    ends_on: string;
    value: string | null;
    slas: { priority: string; response_minutes: number | null; resolve_minutes: number | null }[];
}

const props = defineProps<{
    contract: ContractDetail;
    assetCount: number;
    assets: AssetRow[];
    assetSearch: string;
    candidates: AssetRow[];
    documents: { id: number; name: string; size: number; uploaded_at: string | null }[];
    history: { id: number; description: string; event: string | null; actor: string | null; at: string }[];
    can: { update: boolean; delete: boolean; manageAssets: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('contracts.title'), href: route('contract.contracts.index') },
    { title: props.contract.contract_no, href: route('contract.contracts.show', props.contract.id) },
];

const errors = () => page.props.errors as Record<string, string>;

const destroy = () => {
    if (confirm(t('common.confirm_delete', { name: props.contract.contract_no }))) {
        router.delete(route('contract.contracts.destroy', props.contract.id));
    }
};

// --- assets --------------------------------------------------------------------
const search = ref(props.assetSearch);
const selected = ref<number[]>([]);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.reload({ only: ['candidates', 'assetSearch'], data: { asset_search: value || undefined }, replace: true });
    }, 300);
});

const addSelected = () =>
    router.post(
        route('contract.contracts.assets.store', props.contract.id),
        { asset_ids: selected.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = [];
                search.value = '';
            },
        },
    );

const removeAsset = (asset: AssetRow) => {
    if (confirm(t('contracts.confirm_remove', { name: `${asset.asset_code} ${asset.name}` }))) {
        router.delete(route('contract.contracts.assets.destroy', [props.contract.id, asset.id]), { preserveScroll: true });
    }
};

// --- documents -----------------------------------------------------------------
const upload = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);

const uploadFile = () =>
    upload.post(route('contract.contracts.documents.store', props.contract.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            upload.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });

const deleteDocument = (doc: { id: number; name: string }) => {
    if (confirm(t('common.confirm_delete', { name: doc.name }))) {
        router.delete(route('contract.contracts.documents.destroy', [props.contract.id, doc.id]), { preserveScroll: true });
    }
};

// --- formatting ----------------------------------------------------------------
const money = (baht: string | null) =>
    baht === null ? t('common.none') : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const hours = (minutes: number | null) => (minutes === null ? t('contracts.no_sla') : t('contracts.hours', { hours: minutes / 60 }));
const fileSize = (bytes: number) => (bytes < 1024 * 1024 ? `${Math.ceil(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`);
const dateTime = (iso: string) => new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bangkok' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${contract.contract_no} ${contract.title}`" />

        <div class="max-w-5xl space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm text-muted-foreground">{{ contract.contract_no }}</p>
                    <Heading :title="contract.title" :description="contract.customer?.name ?? ''" />
                </div>
                <div class="flex items-center gap-2">
                    <ContractPhaseBadge :phase="contract.phase" />
                    <Button v-if="can.delete" variant="outline" @click="destroy">{{ t('common.delete') }}</Button>
                    <Button v-if="can.update" as-child>
                        <Link :href="route('contract.contracts.edit', contract.id)">{{ t('common.edit') }}</Link>
                    </Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <dl class="grid gap-x-6 gap-y-4 rounded-md border p-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('contracts.period') }}</dt>
                    <dd class="text-sm">{{ contract.starts_on }} – {{ contract.ends_on }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('contracts.value') }}</dt>
                    <dd class="text-sm">{{ money(contract.value) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('contracts.service_window') }}</dt>
                    <dd class="text-sm">{{ t(`contracts.service_windows.${contract.service_window}`) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('contracts.pm_interval_months') }}</dt>
                    <dd class="text-sm">
                        {{
                            contract.pm_interval_months ? t('contracts.every_months', { months: contract.pm_interval_months }) : t('contracts.no_pm')
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('contracts.notify_days_before') }}</dt>
                    <dd class="text-sm">{{ contract.notify_days_before }}</dd>
                </div>
                <div v-if="contract.customer">
                    <dt class="text-xs text-muted-foreground">{{ t('customers.contact_name') }}</dt>
                    <dd class="text-sm">
                        {{ contract.customer.contact_name ?? t('common.none') }}
                        <span v-if="contract.customer.phone" class="text-muted-foreground"> · {{ contract.customer.phone }}</span>
                    </dd>
                </div>
            </dl>

            <p v-if="contract.notes" class="whitespace-pre-line text-sm">{{ contract.notes }}</p>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('contracts.sla') }}</h3>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('contracts.priority') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('contracts.response_hours') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('contracts.resolve_hours') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="sla in contract.slas" :key="sla.priority" class="border-t">
                                <td class="px-4 py-2 font-medium">{{ t(`contracts.priorities.${sla.priority}`) }}</td>
                                <td class="px-4 py-2">{{ hours(sla.response_minutes) }}</td>
                                <td class="px-4 py-2">{{ hours(sla.resolve_minutes) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="space-y-3">
                <h3 class="text-sm font-semibold">{{ t('contracts.assets') }} ({{ assetCount }})</h3>
                <p v-if="assets.length < assetCount" class="text-xs text-muted-foreground">
                    {{ t('contracts.assets_hidden', { count: assetCount }) }}
                </p>
                <InputError :message="errors().asset_ids" />

                <div v-if="can.manageAssets" class="space-y-2 rounded-md border p-4">
                    <Input v-model="search" type="search" :placeholder="t('contracts.asset_search_placeholder')" />
                    <template v-if="search">
                        <ul v-if="candidates.length" class="max-h-64 divide-y overflow-y-auto text-sm">
                            <li v-for="asset in candidates" :key="asset.id">
                                <label class="flex items-center gap-3 px-1 py-1.5">
                                    <input v-model="selected" type="checkbox" :value="asset.id" class="size-4 rounded border-input" />
                                    <span class="font-mono text-xs">{{ asset.asset_code }}</span>
                                    <span>{{ asset.name }}</span>
                                    <span class="text-xs text-muted-foreground">{{
                                        [asset.category, asset.branch].filter(Boolean).join(' · ')
                                    }}</span>
                                </label>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">{{ t('contracts.no_candidates') }}</p>
                    </template>
                    <Button v-if="selected.length" size="sm" @click="addSelected">{{
                        t('contracts.add_selected', { count: selected.length })
                    }}</Button>
                </div>

                <div v-if="assets.length" class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="asset in assets" :key="asset.id" class="border-t first:border-t-0">
                                <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                    <Link :href="route('asset.assets.show', asset.ulid)" class="text-primary underline-offset-4 hover:underline">
                                        {{ asset.asset_code }}
                                    </Link>
                                </td>
                                <td class="px-4 py-2">
                                    <div>{{ asset.name }}</div>
                                    <div v-if="asset.serial_number" class="text-xs text-muted-foreground">S/N {{ asset.serial_number }}</div>
                                </td>
                                <td class="px-4 py-2">{{ asset.category ?? t('common.none') }}</td>
                                <td class="px-4 py-2">{{ asset.branch ?? t('assets.no_branch') }}</td>
                                <td class="px-4 py-2">{{ t(`assets.statuses.${asset.status}`) }}</td>
                                <td v-if="can.manageAssets" class="px-4 py-2 text-right">
                                    <button
                                        type="button"
                                        class="text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                                        @click="removeAsset(asset)"
                                    >
                                        {{ t('contracts.remove') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else-if="assetCount === 0" class="text-sm text-muted-foreground">{{ t('contracts.no_assets') }}</p>
            </section>

            <section class="space-y-3">
                <h3 class="text-sm font-semibold">{{ t('contracts.documents') }}</h3>
                <ul v-if="documents.length" class="divide-y rounded-md border text-sm">
                    <li v-for="doc in documents" :key="doc.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                        <a
                            :href="route('contract.contracts.documents.show', [contract.id, doc.id])"
                            target="_blank"
                            class="text-primary underline-offset-4 hover:underline"
                        >
                            {{ doc.name }}
                        </a>
                        <span class="flex items-center gap-3 text-xs text-muted-foreground">
                            {{ fileSize(doc.size) }}
                            <span v-if="doc.uploaded_at">· {{ dateTime(doc.uploaded_at) }}</span>
                            <button
                                v-if="can.update"
                                type="button"
                                class="text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                                @click="deleteDocument(doc)"
                            >
                                {{ t('common.delete') }}
                            </button>
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">{{ t('contracts.no_documents') }}</p>

                <form v-if="can.update" class="flex flex-wrap items-center gap-3" @submit.prevent="uploadFile">
                    <input
                        ref="fileInput"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        class="text-sm"
                        :aria-label="t('contracts.upload')"
                        @input="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    />
                    <Button size="sm" :disabled="!upload.file || upload.processing">{{ t('contracts.upload') }}</Button>
                    <span class="text-xs text-muted-foreground">{{ t('contracts.upload_hint') }}</span>
                    <InputError class="w-full" :message="upload.errors.file" />
                </form>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('common.history') }}</h3>
                <ul v-if="history.length" class="divide-y rounded-md border text-sm">
                    <li v-for="entry in history" :key="entry.id" class="flex flex-wrap justify-between gap-2 px-4 py-2">
                        <span class="font-medium">{{
                            entry.event && ['created', 'updated', 'deleted'].includes(entry.event)
                                ? t(`common.events.${entry.event}`)
                                : entry.description
                        }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ t('common.by', { name: entry.actor ?? t('common.system') }) }} · {{ dateTime(entry.at) }}
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">{{ t('common.no_history') }}</p>
            </section>

            <Button variant="outline" as-child>
                <Link :href="route('contract.contracts.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
