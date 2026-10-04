<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import IpStatusBadge from '@/components/IpStatusBadge.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

type Summary = { total: number; available: number; in_use: number; reserved: number; excluded: number; conflict: number };

interface NetworkOption {
    id: number;
    name: string;
    vlan_id: number | null;
    description: string | null;
    customer_id: number | null;
    site_id: number | null;
    customer: string | null;
    site: string | null;
}

interface SubnetOption {
    id: number;
    network_id: number;
    cidr: string;
    gateway: string | null;
    description: string | null;
    network: string | null;
    customer: string | null;
    site: string | null;
    summary: Summary;
}

interface IpRow {
    ip: string;
    status: string;
    conflict: string | null;
    hostname: string | null;
    mac_address: string | null;
    subnet_id: number;
    cidr: string;
    site: string | null;
    customer: string | null;
    // A device the user may not see is counted but not named.
    assets: { ulid: string | null; asset_code: string | null; name: string | null; visible: boolean }[];
}

interface Filters {
    customer: string;
    site: number | null;
    network: number | null;
    subnet: number | null;
    search: string;
    status: string;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    filters: Filters;
    customers: { id: number; code: string; name: string }[];
    sites: { id: number; name: string }[];
    networks: NetworkOption[];
    subnets: SubnetOption[];
    selected: { id: number; cidr: string; summary: Summary } | null;
    rows: Paginated<IpRow> | null;
    suggestions: { subnet_id: number; cidr: string; ips: string[]; count: number } | null;
    statuses: string[];
    can: { run: boolean; manage: boolean };
}>();

const page = usePage<SharedData>();
const errors = computed(() => page.props.errors as Record<string, string>);
const breadcrumbs: BreadcrumbItem[] = [{ title: t('ip_check.title'), href: route('asset.ip-check') }];

const filters = useListFilters('asset.ip-check', props.filters);
// A narrower choice above clears the ones below it.
watch(
    () => filters.customer,
    () => Object.assign(filters, { site: null, network: null, subnet: null }),
);
watch(
    () => filters.site,
    () => Object.assign(filters, { network: null, subnet: null }),
);
watch(
    () => filters.network,
    () => (filters.subnet = null),
);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

const summaryKeys = ['available', 'in_use', 'reserved', 'excluded', 'conflict'] as const;
const totals = computed<Summary>(() =>
    props.selected
        ? props.selected.summary
        : props.subnets.reduce(
              (sum, s) => {
                  (Object.keys(sum) as (keyof Summary)[]).forEach((k) => (sum[k] += s.summary[k]));
                  return sum;
              },
              { total: 0, available: 0, in_use: 0, reserved: 0, excluded: 0, conflict: 0 },
          ),
);

const ipLink = (row: { subnet_id: number; ip: string }) => route('asset.ip-check.ips.show', { subnet: row.subnet_id, ip: row.ip });

// "Find an address": a subnet and how many, then reserve the suggestions.
const finding = ref(props.suggestions !== null);
const find = useForm({
    subnet: props.suggestions?.subnet_id ?? props.filters.subnet ?? props.subnets[0]?.id ?? null,
    count: props.suggestions?.count ?? 1,
});
const runFind = () =>
    router.get(route('asset.ip-check'), { ...cleanFilters(), find: find.subnet, count: find.count }, { preserveState: true, preserveScroll: true });
const reserve = useForm({ ips: [] as string[], purpose: '', notes: '', customer: props.filters.customer });
watch(
    () => props.suggestions,
    (s) => (reserve.ips = s ? [...s.ips] : []),
    { immediate: true },
);
const submitReserve = () => props.suggestions && reserve.post(route('asset.ip-check.reserve', props.suggestions.subnet_id), { preserveScroll: true });

const cleanFilters = () => Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== null && v !== ''));

// Networks and subnets (ip-check.manage)
const editingNetwork = ref<NetworkOption | 'new' | null>(null);
const networkForm = useForm({
    customer_id: null as number | null,
    site_id: null as number | null,
    name: '',
    vlan_id: '' as number | '',
    description: '',
});
const networkSites = ref<{ id: number; name: string }[]>([]);
const openNetwork = (network: NetworkOption | 'new') => {
    editingNetwork.value = network;
    const own = props.filters.customer === 'own';
    networkForm.defaults(
        network === 'new'
            ? {
                  customer_id: own ? null : Number(props.filters.customer) || null,
                  site_id: props.filters.site,
                  name: '',
                  vlan_id: '',
                  description: '',
              }
            : {
                  customer_id: network.customer_id,
                  site_id: network.site_id,
                  name: network.name,
                  vlan_id: network.vlan_id ?? '',
                  description: network.description ?? '',
              },
    );
    networkForm.reset();
    networkForm.clearErrors();
};
// The sites of the customer chosen in the form (the page only carries those of the filter's customer).
watch(
    () => networkForm.customer_id,
    async (id, before) => {
        if (before !== undefined && id !== before) networkForm.site_id = null;
        networkSites.value = id === Number(props.filters.customer) ? props.sites : [];
        if (id && id !== Number(props.filters.customer)) {
            router.reload({
                only: ['sites'],
                data: { customer: id },
                onSuccess: () => (networkSites.value = props.sites),
            });
        }
    },
);
const saveNetwork = () => {
    const options = { preserveScroll: true, onSuccess: () => (editingNetwork.value = null) };
    if (editingNetwork.value === 'new') networkForm.post(route('asset.ip-check.networks.store'), options);
    else if (editingNetwork.value) networkForm.put(route('asset.ip-check.networks.update', editingNetwork.value.id), options);
};
const deleteNetwork = (network: NetworkOption) =>
    confirm(t('ip_check.confirm_delete_network', { name: network.name })) &&
    router.delete(route('asset.ip-check.networks.destroy', network.id), { preserveScroll: true });

const editingSubnet = ref<SubnetOption | 'new' | null>(null);
const subnetForm = useForm({ network_id: null as number | null, cidr: '', gateway: '', description: '' });
const openSubnet = (subnet: SubnetOption | 'new') => {
    editingSubnet.value = subnet;
    subnetForm.defaults(
        subnet === 'new'
            ? { network_id: props.filters.network ?? props.networks[0]?.id ?? null, cidr: '', gateway: '', description: '' }
            : { network_id: subnet.network_id, cidr: subnet.cidr, gateway: subnet.gateway ?? '', description: subnet.description ?? '' },
    );
    subnetForm.reset();
    subnetForm.clearErrors();
};
const saveSubnet = () => {
    const options = { preserveScroll: true, onSuccess: () => (editingSubnet.value = null) };
    if (editingSubnet.value === 'new') subnetForm.post(route('asset.ip-check.subnets.store'), options);
    else if (editingSubnet.value) subnetForm.put(route('asset.ip-check.subnets.update', editingSubnet.value.id), options);
};
const deleteSubnet = (subnet: SubnetOption) =>
    confirm(t('ip_check.confirm_delete_subnet', { cidr: subnet.cidr })) &&
    router.delete(route('asset.ip-check.subnets.destroy', subnet.id), { preserveScroll: true });

const currentNetwork = computed(() => props.networks.find((n) => n.id === filters.network) ?? null);
const currentSubnet = computed(() => props.subnets.find((s) => s.id === filters.subnet) ?? null);

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('ip_check.title')" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('ip_check.title')" :description="t('ip_check.description')" />
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.run && subnets.length" @click="finding = !finding">
                        <Search class="h-4 w-4" />
                        {{ t('ip_check.find') }}
                    </Button>
                    <Button v-if="can.manage" variant="outline" @click="openNetwork('new')">
                        <Plus class="h-4 w-4" />
                        {{ t('ip_check.add_network') }}
                    </Button>
                    <Button v-if="can.manage && networks.length" variant="outline" @click="openSubnet('new')">
                        <Plus class="h-4 w-4" />
                        {{ t('ip_check.add_subnet') }}
                    </Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.network || errors.subnet" />

            <!-- Filters: customer -> site -> network -> subnet -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="grid gap-1.5">
                    <Label for="customer">{{ t('ip_check.customer') }}</Label>
                    <select id="customer" v-model="filters.customer" :class="selectClass">
                        <option value="">{{ t('ip_check.all') }}</option>
                        <option value="own">{{ t('ip_check.own') }}</option>
                        <option v-for="c in customers" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="site">{{ t('ip_check.site') }}</Label>
                    <select id="site" v-model="filters.site" :class="selectClass" :disabled="!sites.length">
                        <option :value="null">{{ t('ip_check.all_sites') }}</option>
                        <option v-for="s in sites" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="network">{{ t('ip_check.network') }}</Label>
                    <select id="network" v-model="filters.network" :class="selectClass">
                        <option :value="null">{{ t('ip_check.all_networks') }}</option>
                        <option v-for="n in networks" :key="n.id" :value="n.id">
                            {{ n.name }}{{ n.vlan_id ? ` (VLAN ${n.vlan_id})` : '' }}{{ n.customer && !filters.customer ? ` · ${n.customer}` : '' }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="subnet">{{ t('ip_check.subnet') }}</Label>
                    <select id="subnet" v-model="filters.subnet" :class="selectClass">
                        <option :value="null">{{ t('ip_check.all_subnets') }}</option>
                        <option v-for="s in subnets" :key="s.id" :value="s.id">{{ s.cidr }} · {{ s.network }}</option>
                    </select>
                </div>
            </div>

            <!-- Edit the chosen network / subnet -->
            <div v-if="can.manage && (currentNetwork || currentSubnet)" class="flex flex-wrap gap-2 text-sm">
                <template v-if="currentNetwork">
                    <Button size="sm" variant="ghost" @click="openNetwork(currentNetwork)"
                        ><Pencil class="h-3.5 w-3.5" />{{ t('ip_check.edit_network') }}</Button
                    >
                    <Button size="sm" variant="ghost" class="text-red-600" @click="deleteNetwork(currentNetwork)">
                        <Trash2 class="h-3.5 w-3.5" />{{ t('ip_check.delete_network') }}
                    </Button>
                </template>
                <template v-if="currentSubnet">
                    <Button size="sm" variant="ghost" @click="openSubnet(currentSubnet)"
                        ><Pencil class="h-3.5 w-3.5" />{{ t('ip_check.edit_subnet') }}</Button
                    >
                    <Button size="sm" variant="ghost" class="text-red-600" @click="deleteSubnet(currentSubnet)">
                        <Trash2 class="h-3.5 w-3.5" />{{ t('ip_check.delete_subnet') }}
                    </Button>
                </template>
            </div>

            <!-- Network form -->
            <form v-if="editingNetwork" class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2" @submit.prevent="saveNetwork">
                <h3 class="font-semibold sm:col-span-2">{{ editingNetwork === 'new' ? t('ip_check.add_network') : t('ip_check.edit_network') }}</h3>
                <div class="grid gap-1.5">
                    <Label for="n_customer">{{ t('ip_check.owner') }}</Label>
                    <select id="n_customer" v-model="networkForm.customer_id" :class="selectClass">
                        <option :value="null">{{ t('ip_check.own') }}</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <InputError :message="networkForm.errors.customer_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="n_site">{{ t('ip_check.site') }}</Label>
                    <select id="n_site" v-model="networkForm.site_id" :class="selectClass" :disabled="!networkForm.customer_id">
                        <option :value="null">{{ t('ip_check.no_site') }}</option>
                        <option v-for="s in networkSites" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <InputError :message="networkForm.errors.site_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="n_name">{{ t('ip_check.network_name') }}</Label>
                    <Input id="n_name" v-model="networkForm.name" :placeholder="t('ip_check.network_name_placeholder')" required />
                    <InputError :message="networkForm.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="n_vlan">{{ t('ip_check.vlan') }}</Label>
                    <Input id="n_vlan" v-model.number="networkForm.vlan_id" type="number" min="1" max="4094" />
                    <InputError :message="networkForm.errors.vlan_id" />
                </div>
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="n_desc">{{ t('ip_check.description_field') }}</Label>
                    <Input id="n_desc" v-model="networkForm.description" />
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <Button :disabled="networkForm.processing">{{ t('ip_check.save') }}</Button>
                    <Button type="button" variant="ghost" @click="editingNetwork = null">{{ t('ip_check.cancel') }}</Button>
                </div>
            </form>

            <!-- Subnet form -->
            <form v-if="editingSubnet" class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2" @submit.prevent="saveSubnet">
                <h3 class="font-semibold sm:col-span-2">{{ editingSubnet === 'new' ? t('ip_check.add_subnet') : t('ip_check.edit_subnet') }}</h3>
                <div class="grid gap-1.5">
                    <Label for="s_network">{{ t('ip_check.network') }}</Label>
                    <select id="s_network" v-model="subnetForm.network_id" :class="selectClass" required>
                        <option v-for="n in networks" :key="n.id" :value="n.id">{{ n.name }}{{ n.customer ? ` · ${n.customer}` : '' }}</option>
                    </select>
                    <InputError :message="subnetForm.errors.network_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="s_cidr">{{ t('ip_check.cidr') }}</Label>
                    <Input id="s_cidr" v-model="subnetForm.cidr" class="font-mono" :placeholder="t('ip_check.cidr_placeholder')" required />
                    <InputError :message="subnetForm.errors.cidr" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="s_gateway">{{ t('ip_check.gateway') }}</Label>
                    <Input id="s_gateway" v-model="subnetForm.gateway" class="font-mono" placeholder="192.168.1.1" />
                    <InputError :message="subnetForm.errors.gateway" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="s_desc">{{ t('ip_check.description_field') }}</Label>
                    <Input id="s_desc" v-model="subnetForm.description" />
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <Button :disabled="subnetForm.processing">{{ t('ip_check.save') }}</Button>
                    <Button type="button" variant="ghost" @click="editingSubnet = null">{{ t('ip_check.cancel') }}</Button>
                </div>
            </form>

            <!-- Find an address for a new device -->
            <section v-if="finding && can.run" class="space-y-4 rounded-lg border p-4">
                <h3 class="font-semibold">{{ t('ip_check.find') }}</h3>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="runFind">
                    <div class="grid min-w-56 flex-1 gap-1.5">
                        <Label for="find_subnet">{{ t('ip_check.subnet') }}</Label>
                        <select id="find_subnet" v-model="find.subnet" :class="selectClass" required>
                            <option v-for="s in subnets" :key="s.id" :value="s.id">
                                {{ s.cidr }} · {{ s.network }}{{ s.site ? ` · ${s.site}` : '' }} —
                                {{ t('ip_check.free_of', { free: s.summary.available, total: s.summary.total }) }}
                            </option>
                        </select>
                    </div>
                    <div class="grid w-32 gap-1.5">
                        <Label for="find_count">{{ t('ip_check.find_count') }}</Label>
                        <Input id="find_count" v-model.number="find.count" type="number" min="1" max="50" />
                    </div>
                    <Button>{{ t('ip_check.find_submit') }}</Button>
                </form>

                <template v-if="suggestions">
                    <p v-if="!suggestions.ips.length" class="text-sm text-muted-foreground">{{ t('ip_check.none_free') }}</p>
                    <form v-else class="space-y-3" @submit.prevent="submitReserve">
                        <div>
                            <p class="mb-2 text-sm text-muted-foreground">
                                {{ t('ip_check.suggested') }} ({{ suggestions.cidr }})
                                <template v-if="suggestions.ips.length < suggestions.count">
                                    · {{ t('ip_check.fewer_free', { count: suggestions.ips.length }) }}</template
                                >
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="ip in suggestions.ips" :key="ip" class="flex items-center gap-2 rounded-md border px-3 py-1.5">
                                    <input v-model="reserve.ips" type="checkbox" :value="ip" class="size-4" />
                                    <span class="font-mono">{{ ip }}</span>
                                    <IpStatusBadge status="available" />
                                </label>
                            </div>
                            <InputError :message="reserve.errors.ips" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="purpose">{{ t('ip_check.purpose') }}</Label>
                                <Input id="purpose" v-model="reserve.purpose" :placeholder="t('ip_check.purpose_placeholder')" required />
                                <InputError :message="reserve.errors.purpose" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="notes">{{ t('ip_check.notes') }}</Label>
                                <Input id="notes" v-model="reserve.notes" />
                            </div>
                        </div>
                        <Button :disabled="reserve.processing || !reserve.ips.length">{{ t('ip_check.reserve') }}</Button>
                    </form>
                </template>
            </section>

            <!-- Summary: free addresses first -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-lg border p-3">
                    <div class="text-sm text-muted-foreground">{{ t('ip_check.total') }}</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ totals.total }}</div>
                </div>
                <button
                    v-for="key in summaryKeys"
                    :key="key"
                    type="button"
                    class="rounded-lg border p-3 text-left hover:bg-muted"
                    :class="{ 'ring-2 ring-primary': filters.status === key }"
                    @click="filters.status = filters.status === key ? '' : key"
                >
                    <div class="text-sm text-muted-foreground">{{ t(`ip_check.statuses.${key}`) }}</div>
                    <div
                        class="text-2xl font-semibold tabular-nums"
                        :class="{
                            'text-green-700 dark:text-green-400': key === 'available',
                            'text-red-700 dark:text-red-400': key === 'conflict' && totals.conflict,
                        }"
                    >
                        {{ totals[key] }}
                    </div>
                </button>
            </div>

            <!-- Search and status -->
            <div class="flex flex-wrap gap-3">
                <Input v-model="filters.search" type="search" class="max-w-md flex-1" :placeholder="t('ip_check.search_placeholder')" />
                <select v-model="filters.status" :class="selectClass" class="w-auto">
                    <option value="">{{ t('ip_check.all_statuses') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`ip_check.statuses.${s}`) }}</option>
                </select>
            </div>

            <!-- No subnet chosen: the subnets with their free addresses -->
            <template v-if="!rows">
                <p v-if="!subnets.length" class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                    {{ t('ip_check.no_subnets') }}<template v-if="can.manage"><br />{{ t('ip_check.no_subnets_manage') }}</template>
                </p>
                <template v-else>
                    <p class="text-sm text-muted-foreground">{{ t('ip_check.pick_subnet') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="s in subnets"
                            :key="s.id"
                            type="button"
                            class="space-y-1 rounded-lg border p-4 text-left hover:bg-muted"
                            @click="filters.subnet = s.id"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono font-semibold">{{ s.cidr }}</span>
                                <span class="text-sm text-green-700 dark:text-green-400">{{
                                    t('ip_check.free_of', { free: s.summary.available, total: s.summary.total })
                                }}</span>
                            </div>
                            <div class="text-sm">{{ s.network }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ [s.customer ?? t('ip_check.own'), s.site].filter(Boolean).join(' · ') }}
                            </div>
                            <div v-if="s.summary.conflict" class="text-xs text-red-700 dark:text-red-400">
                                {{ t('ip_check.statuses.conflict') }} {{ s.summary.conflict }}
                            </div>
                        </button>
                    </div>
                </template>
            </template>

            <!-- The addresses -->
            <template v-else>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th v-for="col in ['ip', 'status', 'hostname']" :key="col" class="px-3 py-2 font-semibold">
                                    <button type="button" class="inline-flex items-center gap-1" @click="sortBy(col)">
                                        {{ t(`ip_check.${col}`) }}
                                        <ArrowUp v-if="filters.sort === col && filters.direction === 'asc'" class="h-3.5 w-3.5" />
                                        <ArrowDown v-else-if="filters.sort === col" class="h-3.5 w-3.5" />
                                    </button>
                                </th>
                                <th class="px-3 py-2 font-semibold">{{ t('ip_check.asset') }}</th>
                                <th class="hidden px-3 py-2 font-semibold md:table-cell">{{ t('ip_check.mac') }}</th>
                                <th class="hidden px-3 py-2 font-semibold lg:table-cell">{{ t('ip_check.site') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows.data" :key="`${row.subnet_id}-${row.ip}`" class="border-t hover:bg-muted/30">
                                <td class="px-3 py-2">
                                    <Link :href="ipLink(row)" class="font-mono text-primary underline-offset-4 hover:underline">{{ row.ip }}</Link>
                                    <div v-if="!selected" class="text-xs text-muted-foreground">{{ row.cidr }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <IpStatusBadge :status="row.status" />
                                    <div v-if="row.conflict" class="mt-0.5 text-xs text-red-700 dark:text-red-400">
                                        {{ t(`ip_check.conflicts.${row.conflict}`) }}
                                    </div>
                                </td>
                                <td class="px-3 py-2">{{ row.hostname ?? '' }}</td>
                                <td class="px-3 py-2">
                                    <div v-for="(asset, i) in row.assets" :key="i">
                                        <Link
                                            v-if="asset.visible && asset.ulid"
                                            :href="route('asset.assets.show', asset.ulid)"
                                            class="hover:underline"
                                        >
                                            <span class="font-mono text-xs">{{ asset.asset_code }}</span> {{ asset.name }}
                                        </Link>
                                        <span v-else class="text-xs text-muted-foreground">{{ t('ip_check.hidden_device') }}</span>
                                    </div>
                                </td>
                                <td class="hidden px-3 py-2 font-mono text-xs md:table-cell">{{ row.mac_address ?? '' }}</td>
                                <td class="hidden px-3 py-2 lg:table-cell">{{ row.site ?? '' }}</td>
                            </tr>
                            <tr v-if="!rows.data.length">
                                <td colspan="6" class="px-3 py-6 text-center text-muted-foreground">{{ t('ip_check.no_rows') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="rows" />
            </template>

            <p class="text-xs text-muted-foreground">{{ t('ip_check.note') }}</p>
        </div>
    </AppLayout>
</template>
