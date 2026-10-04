<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import IpStatusBadge from '@/components/IpStatusBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface AssetRef {
    id: number;
    ulid: string | null;
    asset_code: string | null;
    name: string | null;
    serial_number: string | null;
    location: string | null;
    visible: boolean;
}

const props = defineProps<{
    address: {
        ip: string;
        status: string;
        conflict: string | null;
        ulid: string | null;
        hostname: string | null;
        mac_address: string | null;
        notes: string | null;
        assets: AssetRef[];
        subnet_id: number;
        cidr: string;
        gateway: string | null;
        network: string | null;
        vlan_id: number | null;
        customer: string | null;
        site: string | null;
        responsible_id: number | null;
        responsible: string | null;
        in_use_since: string | null;
    };
    reservations: {
        id: number;
        purpose: string;
        notes: string | null;
        status: string;
        reserved_by: string | null;
        reserved_at: string;
        ended_at: string | null;
    }[];
    histories: {
        id: number;
        action: string;
        asset_code: string | null;
        hostname: string | null;
        mac_address: string | null;
        notes: string | null;
        user: string | null;
        at: string;
    }[];
    tickets: { ulid: string; ticket_no: string; title: string; status: string; created_at: string }[];
    staff: { id: number; name: string }[];
    assetOptions: { id: number; ulid: string; asset_code: string; name: string; serial_number: string | null; location: string | null }[];
    can: { run: boolean; manage: boolean; viewAssets: boolean };
}>();

const page = usePage<SharedData>();
const errors = computed(() => page.props.errors as Record<string, string>);
const a = computed(() => props.address);
const params = computed(() => ({ subnet: a.value.subnet_id, ip: a.value.ip }));

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('ip_check.title'), href: route('asset.ip-check') },
    { title: props.address.cidr, href: route('asset.ip-check', { subnet: props.address.subnet_id }) },
    { title: props.address.ip, href: route('asset.ip-check.ips.show', { subnet: props.address.subnet_id, ip: props.address.ip }) },
];

const dateTime = (iso: string) => new Date(iso).toLocaleString('th-TH-u-ca-gregory', { dateStyle: 'medium', timeStyle: 'short' });
const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('th-TH-u-ca-gregory', { dateStyle: 'medium' }) : t('ip_check.none'));
const location = computed(() => a.value.assets.find((x) => x.visible)?.location ?? null);

// Give to a device: search an asset, then hostname / MAC / who / since.
const assigning = ref(false);
const assetSearch = ref('');
const picked = ref<(typeof props.assetOptions)[number] | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(assetSearch, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.reload({ only: ['assetOptions'], data: { asset_search: value } }), 300);
});
const assign = useForm({
    asset_id: null as number | null,
    hostname: props.address.hostname ?? '',
    mac_address: props.address.mac_address ?? '',
    responsible_id: props.address.responsible_id,
    in_use_since: new Date().toISOString().slice(0, 10),
    notes: '',
});
const pick = (asset: (typeof props.assetOptions)[number]) => {
    picked.value = asset;
    assign.asset_id = asset.id;
    assetSearch.value = '';
};
const submitAssign = () =>
    assign.post(route('asset.ip-check.ips.assign', params.value), { preserveScroll: true, onSuccess: () => (assigning.value = false) });

const release = () =>
    confirm(t('ip_check.confirm_release', { ip: a.value.ip })) &&
    router.post(route('asset.ip-check.ips.release', params.value), {}, { preserveScroll: true });
const setExcluded = (excluded: boolean) => router.post(route('asset.ip-check.ips.exclude', params.value), { excluded }, { preserveScroll: true });

// Edit the details
const editing = ref(false);
const details = useForm({
    hostname: props.address.hostname ?? '',
    mac_address: props.address.mac_address ?? '',
    responsible_id: props.address.responsible_id,
    in_use_since: props.address.in_use_since ?? '',
    notes: props.address.notes ?? '',
});
const saveDetails = () =>
    details.put(route('asset.ip-check.ips.update', params.value), { preserveScroll: true, onSuccess: () => (editing.value = false) });

const reserve = useForm({ ips: [props.address.ip], purpose: '', notes: '', back: true });
const reserving = ref(false);
const submitReserve = () =>
    reserve.post(route('asset.ip-check.reserve', props.address.subnet_id), {
        preserveScroll: true,
        onSuccess: () => (reserving.value = false),
    });

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${t('ip_check.detail')} ${address.ip}`" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Heading
                        :title="address.ip"
                        :description="[address.customer ?? t('ip_check.own'), address.site, address.network].filter(Boolean).join(' · ')"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <template v-if="can.run">
                        <Button v-if="address.status === 'available'" @click="reserving = !reserving">{{ t('ip_check.reserve') }}</Button>
                        <Button v-if="['available', 'reserved'].includes(address.status)" variant="outline" @click="assigning = !assigning">
                            {{ t('ip_check.assign') }}
                        </Button>
                        <Button v-if="['in_use', 'reserved', 'conflict'].includes(address.status) && address.ulid" variant="outline" @click="release">
                            {{ t('ip_check.release') }}
                        </Button>
                        <Button v-if="address.ulid || address.status !== 'available'" variant="ghost" @click="editing = !editing">{{
                            t('ip_check.edit_details')
                        }}</Button>
                    </template>
                    <template v-if="can.manage">
                        <Button v-if="address.status === 'available'" variant="ghost" @click="setExcluded(true)">{{ t('ip_check.exclude') }}</Button>
                        <Button v-if="address.status === 'excluded'" variant="ghost" @click="setExcluded(false)">{{ t('ip_check.include') }}</Button>
                    </template>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.status || errors.asset_id || errors.ips" />

            <!-- The address -->
            <dl class="grid gap-x-6 gap-y-4 rounded-lg border p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.status') }}</dt>
                    <dd class="mt-1">
                        <IpStatusBadge :status="address.status" />
                        <div v-if="address.conflict" class="mt-1 text-red-700 dark:text-red-400">
                            {{ t(`ip_check.conflicts.${address.conflict}`) }}
                        </div>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.subnet') }}</dt>
                    <dd class="mt-1 font-mono">
                        {{ address.cidr }}<span v-if="address.gateway" class="text-muted-foreground"> · GW {{ address.gateway }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.network') }}</dt>
                    <dd class="mt-1">
                        {{ address.network ?? t('ip_check.none') }}<span v-if="address.vlan_id"> (VLAN {{ address.vlan_id }})</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.hostname') }}</dt>
                    <dd class="mt-1">{{ address.hostname ?? t('ip_check.none') }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.mac') }}</dt>
                    <dd class="mt-1 font-mono">{{ address.mac_address ?? t('ip_check.none') }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.asset') }}</dt>
                    <dd class="mt-1 space-y-0.5">
                        <template v-for="asset in address.assets" :key="asset.id">
                            <Link
                                v-if="asset.visible && asset.ulid && can.viewAssets"
                                :href="route('asset.assets.show', asset.ulid)"
                                class="block text-primary hover:underline"
                            >
                                <span class="font-mono">{{ asset.asset_code }}</span> {{ asset.name }}
                            </Link>
                            <span v-else class="block text-muted-foreground">{{ t('ip_check.hidden_device') }}</span>
                        </template>
                        <span v-if="!address.assets.length">{{ t('ip_check.none') }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.customer_field') }}</dt>
                    <dd class="mt-1">{{ address.customer ?? t('ip_check.own') }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.site') }}</dt>
                    <dd class="mt-1">{{ address.site ?? t('ip_check.none') }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.location') }}</dt>
                    <dd class="mt-1">{{ location ?? t('ip_check.none') }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.responsible') }}</dt>
                    <dd class="mt-1">{{ address.responsible ?? t('ip_check.none') }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ t('ip_check.in_use_since') }}</dt>
                    <dd class="mt-1">{{ date(address.in_use_since) }}</dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-muted-foreground">{{ t('ip_check.notes') }}</dt>
                    <dd class="mt-1 whitespace-pre-line">{{ address.notes ?? t('ip_check.none') }}</dd>
                </div>
            </dl>

            <!-- Reserve this address -->
            <form v-if="reserving" class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2" @submit.prevent="submitReserve">
                <h3 class="font-semibold sm:col-span-2">{{ t('ip_check.reserve') }} {{ address.ip }}</h3>
                <div class="grid gap-1.5">
                    <Label for="purpose">{{ t('ip_check.purpose') }}</Label>
                    <Input id="purpose" v-model="reserve.purpose" :placeholder="t('ip_check.purpose_placeholder')" required />
                    <InputError :message="reserve.errors.purpose || reserve.errors.ips" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="r_notes">{{ t('ip_check.notes') }}</Label>
                    <Input id="r_notes" v-model="reserve.notes" />
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <Button :disabled="reserve.processing">{{ t('ip_check.reserve') }}</Button>
                    <Button type="button" variant="ghost" @click="reserving = false">{{ t('ip_check.cancel') }}</Button>
                </div>
            </form>

            <!-- Give to a device -->
            <form v-if="assigning" class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2" @submit.prevent="submitAssign">
                <h3 class="font-semibold sm:col-span-2">{{ t('ip_check.assign') }}</h3>
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="asset_search">{{ t('ip_check.asset') }}</Label>
                    <div v-if="picked" class="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                        <span
                            ><span class="font-mono">{{ picked.asset_code }}</span> {{ picked.name }}</span
                        >
                        <button type="button" class="text-xs text-primary hover:underline" @click="((picked = null), (assign.asset_id = null))">
                            {{ t('tickets.change_asset') }}
                        </button>
                    </div>
                    <template v-else>
                        <Input id="asset_search" v-model="assetSearch" type="search" autocomplete="off" :placeholder="t('ip_check.asset_search')" />
                        <ul v-if="assetSearch && assetOptions.length" class="max-h-56 divide-y overflow-y-auto rounded-md border text-sm">
                            <li v-for="asset in assetOptions" :key="asset.id">
                                <button type="button" class="w-full px-3 py-1.5 text-left hover:bg-muted" @click="pick(asset)">
                                    <span class="font-mono text-xs">{{ asset.asset_code }}</span>
                                    <span class="ml-2">{{ asset.name }}</span>
                                    <span class="ml-2 text-xs text-muted-foreground">{{
                                        [asset.serial_number, asset.location].filter(Boolean).join(' · ')
                                    }}</span>
                                </button>
                            </li>
                        </ul>
                    </template>
                    <InputError :message="assign.errors.asset_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="a_hostname">{{ t('ip_check.hostname') }}</Label>
                    <Input id="a_hostname" v-model="assign.hostname" placeholder="AP-F2-01" />
                    <InputError :message="assign.errors.hostname" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="a_mac">{{ t('ip_check.mac') }}</Label>
                    <Input id="a_mac" v-model="assign.mac_address" class="font-mono" placeholder="AA:BB:CC:DD:EE:FF" />
                    <InputError :message="assign.errors.mac_address" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="a_responsible">{{ t('ip_check.responsible') }}</Label>
                    <select id="a_responsible" v-model="assign.responsible_id" :class="selectClass">
                        <option :value="null">{{ t('ip_check.none') }}</option>
                        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="a_since">{{ t('ip_check.in_use_since') }}</Label>
                    <Input id="a_since" v-model="assign.in_use_since" type="date" />
                </div>
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="a_notes">{{ t('ip_check.notes') }}</Label>
                    <Input id="a_notes" v-model="assign.notes" />
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <Button :disabled="assign.processing || !assign.asset_id">{{ t('ip_check.assign_submit') }}</Button>
                    <Button type="button" variant="ghost" @click="assigning = false">{{ t('ip_check.cancel') }}</Button>
                </div>
            </form>

            <!-- Edit the details -->
            <form v-if="editing" class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2" @submit.prevent="saveDetails">
                <h3 class="font-semibold sm:col-span-2">{{ t('ip_check.edit_details') }}</h3>
                <div class="grid gap-1.5">
                    <Label for="d_hostname">{{ t('ip_check.hostname') }}</Label>
                    <Input id="d_hostname" v-model="details.hostname" />
                    <InputError :message="details.errors.hostname" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="d_mac">{{ t('ip_check.mac') }}</Label>
                    <Input id="d_mac" v-model="details.mac_address" class="font-mono" />
                    <InputError :message="details.errors.mac_address" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="d_responsible">{{ t('ip_check.responsible') }}</Label>
                    <select id="d_responsible" v-model="details.responsible_id" :class="selectClass">
                        <option :value="null">{{ t('ip_check.none') }}</option>
                        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="d_since">{{ t('ip_check.in_use_since') }}</Label>
                    <Input id="d_since" v-model="details.in_use_since" type="date" />
                </div>
                <div class="grid gap-1.5 sm:col-span-2">
                    <Label for="d_notes">{{ t('ip_check.notes') }}</Label>
                    <textarea
                        id="d_notes"
                        v-model="details.notes"
                        rows="3"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <Button :disabled="details.processing">{{ t('ip_check.save') }}</Button>
                    <Button type="button" variant="ghost" @click="editing = false">{{ t('ip_check.cancel') }}</Button>
                </div>
            </form>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Tickets about the address -->
                <section class="space-y-3">
                    <h3 class="font-semibold">{{ t('ip_check.tickets') }}</h3>
                    <ul v-if="tickets.length" class="divide-y rounded-lg border text-sm">
                        <li v-for="ticket in tickets" :key="ticket.ulid" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <Link :href="route('service.tickets.show', ticket.ulid)" class="text-primary hover:underline">
                                <span class="font-mono">{{ ticket.ticket_no }}</span> {{ ticket.title }}
                            </Link>
                            <TicketStatusBadge :status="ticket.status" />
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">{{ t('ip_check.no_tickets') }}</p>
                </section>

                <!-- Reservations -->
                <section v-if="reservations.length" class="space-y-3">
                    <h3 class="font-semibold">{{ t('ip_check.reservations') }}</h3>
                    <ul class="divide-y rounded-lg border text-sm">
                        <li v-for="r in reservations" :key="r.id" class="space-y-0.5 px-3 py-2">
                            <div class="flex flex-wrap justify-between gap-2">
                                <span>{{ r.purpose }}</span>
                                <span class="text-xs text-muted-foreground">{{ t(`ip_check.reservation_statuses.${r.status}`) }}</span>
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ t('ip_check.reserved_by') }} {{ r.reserved_by ?? t('ip_check.none') }} · {{ dateTime(r.reserved_at) }}
                            </div>
                            <div v-if="r.notes" class="text-xs">{{ r.notes }}</div>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- History -->
            <section class="space-y-3">
                <h3 class="font-semibold">{{ t('ip_check.history') }}</h3>
                <ol v-if="histories.length" class="divide-y rounded-lg border text-sm">
                    <li v-for="h in histories" :key="h.id" class="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                        <span>
                            {{ t(`ip_check.actions.${h.action}`) }}
                            <span v-if="h.asset_code" class="font-mono text-xs"> · {{ h.asset_code }}</span>
                            <span v-if="h.hostname" class="text-xs"> · {{ h.hostname }}</span>
                            <span v-if="h.mac_address" class="font-mono text-xs"> · {{ h.mac_address }}</span>
                            <span v-if="h.notes" class="block text-xs text-muted-foreground">{{ h.notes }}</span>
                        </span>
                        <span class="text-xs text-muted-foreground">{{ h.user ?? '' }} · {{ dateTime(h.at) }}</span>
                    </li>
                </ol>
                <p v-else class="text-sm text-muted-foreground">{{ t('ip_check.no_history') }}</p>
            </section>
        </div>
    </AppLayout>
</template>
