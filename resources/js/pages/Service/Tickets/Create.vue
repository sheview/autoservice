<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
}

interface CoveringContract {
    id: number;
    contract_no: string;
    title: string;
    service_window: string;
    slas: Record<string, { response_minutes: number; resolve_minutes: number }>;
}

const props = defineProps<{
    preset: { asset: AssetOption | null; customer_id: number | null };
    customers: { id: number; code: string; name: string }[];
    assetOptions: AssetOption[];
    contracts: CoveringContract[];
    assignees: { id: number; name: string }[];
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
});

const selectedAsset = ref<AssetOption | null>(props.preset.asset);
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
        reload(['contracts', 'assetOptions']);
    },
);

const pickAsset = (asset: AssetOption) => {
    selectedAsset.value = asset;
    form.asset_id = asset.id;
    if (asset.customer_id) form.customer_id = asset.customer_id;
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

        <div class="max-w-3xl p-4">
            <Heading :title="t('tickets.create')" />

            <form class="space-y-8" @submit.prevent="submit">
                <section class="grid gap-6 sm:grid-cols-2">
                    <div v-if="customers.length" class="grid gap-2 sm:col-span-2">
                        <Label for="customer_id">{{ t('tickets.customer') }}</Label>
                        <select id="customer_id" v-model="form.customer_id" :class="selectClass">
                            <option :value="null">{{ t('tickets.no_customer') }}</option>
                            <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                {{ customer.name }} ({{ customer.code }})
                            </option>
                        </select>
                        <InputError :message="form.errors.customer_id" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
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

                    <div class="grid gap-2 sm:col-span-2">
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
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="title">{{ t('tickets.title_field') }}</Label>
                        <Input id="title" v-model="form.title" required />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="description">{{ t('tickets.description_field') }}</Label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="4"
                            class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="grid gap-2">
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

                    <div class="grid gap-2">
                        <Label for="source">{{ t('tickets.source') }}</Label>
                        <select id="source" v-model="form.source" :class="selectClass">
                            <option v-for="source in sources" :key="source" :value="source">{{ t(`tickets.sources.${source}`) }}</option>
                        </select>
                        <InputError :message="form.errors.source" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="contact_name">{{ t('tickets.contact_name') }}</Label>
                        <Input id="contact_name" v-model="form.contact_name" />
                        <InputError :message="form.errors.contact_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="contact_phone">{{ t('tickets.contact_phone') }}</Label>
                        <Input id="contact_phone" v-model="form.contact_phone" type="tel" />
                        <InputError :message="form.errors.contact_phone" />
                    </div>

                    <div v-if="assignees.length" class="grid gap-2 sm:col-span-2">
                        <Label for="assignee_id">{{ t('tickets.assignee') }}</Label>
                        <select id="assignee_id" v-model="form.assignee_id" :class="selectClass">
                            <option :value="null">{{ t('tickets.unassigned') }}</option>
                            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                        <InputError :message="form.errors.assignee_id" />
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
