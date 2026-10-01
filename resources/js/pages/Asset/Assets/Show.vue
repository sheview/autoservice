<script setup lang="ts">
import AssetCheckoutPanel from '@/components/AssetCheckoutPanel.vue';
import AssetStatusBadge from '@/components/AssetStatusBadge.vue';
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import ContractPhaseBadge from '@/components/ContractPhaseBadge.vue';
import Heading from '@/components/Heading.vue';
import PhotoSlots from '@/components/PhotoSlots.vue';
import PmResultBadge from '@/components/PmResultBadge.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import TicketPriorityBadge from '@/components/TicketPriorityBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Barcode, Plus } from 'lucide-vue-next';
import { computed } from 'vue';

interface AssetDetail {
    ulid: string;
    asset_code: string;
    name: string;
    brand: string | null;
    model: string | null;
    subtype: string | null;
    serial_number: string | null;
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
    notes: string | null;
    category: string | null;
    branch: string | null;
    customer: string | null;
    purchased_at: string | null;
    purchase_price: string | null;
    warranty_expires_at: string | null;
    specs: { label: string; value: string | number | null }[];
}

interface SameModelUnit {
    ulid: string;
    asset_code: string;
    serial_number: string | null;
    property_no: string | null;
    status: string;
    location: string | null;
    branch: string | null;
    // Who has it now (issued or lent).
    holder: string | null;
}

interface HistoryEntry {
    id: number;
    event: string | null;
    actor: string | null;
    changed: string[];
    at: string;
}

interface AssetContract {
    id: number;
    contract_no: string;
    title: string;
    customer: string | null;
    starts_on: string;
    ends_on: string;
    phase: string;
    covering: boolean;
    service_window: string;
}

const props = defineProps<{
    asset: AssetDetail;
    sameModel: SameModelUnit[];
    // Issue/loan; null = the user has nothing to do with it.
    checkouts: InstanceType<typeof AssetCheckoutPanel>['$props']['checkouts'] | null;
    photos: { slot: number; action: string; url: string | null }[];
    attachments: Attachment[];
    history: HistoryEntry[];
    contracts: AssetContract[] | null;
    tickets: { ulid: string; ticket_no: string; title: string; status: string; priority: string; created_at: string }[] | null;
    pmHistory: { visit_ulid: string; visit_no: string; visit_status: string; due_on: string; result: string; checked_at: string | null }[] | null;
    can: { create: boolean; update: boolean; delete: boolean; openTicket: boolean; printLabel: boolean };
}>();

const spareCount = computed(() => props.sameModel.filter((unit) => unit.status === 'spare').length);

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('assets.title'), href: route('asset.assets.index') },
    { title: props.asset.asset_code, href: route('asset.assets.show', props.asset.ulid) },
];

const destroy = () => {
    if (confirm(t('common.confirm_delete', { name: `${props.asset.asset_code} ${props.asset.name}` }))) {
        router.delete(route('asset.assets.destroy', props.asset.ulid));
    }
};

const money = (baht: string | null) =>
    baht === null ? t('common.none') : Number(baht).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Internal UI shows the Gregorian year (PDF documents use the Buddhist year).
const dateTime = (iso: string) => new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bangkok' });

// Logged attribute names (branch_id, category_id, ...) to their labels.
const fieldLabel = (field: string) => t(`assets.${field.replace(/_id$/, '')}`);

const details: [string, (a: AssetDetail) => string | null][] = [
    ['category', (a) => a.category],
    ['branch', (a) => a.branch ?? t('assets.no_branch')],
    ['customer', (a) => a.customer ?? t('assets.owner_company')],
    ['location', (a) => a.location],
    ['used_by', (a) => [a.used_by, a.department].filter(Boolean).join(' · ') || null],
    ['ip_address', (a) => a.ip_address],
    ['mac_address', (a) => a.mac_address],
    ['brand', (a) => a.brand],
    ['model', (a) => a.model],
    ['subtype', (a) => a.subtype],
    ['serial_number', (a) => (a.serials.length ? a.serials.join(', ') : null)],
    ['quantity', (a) => [a.quantity.toLocaleString('th-TH'), a.unit].filter(Boolean).join(' ')],
    ['property_no', (a) => a.property_no],
    ['purchased_at', (a) => a.purchased_at],
    ['purchase_price', (a) => money(a.purchase_price)],
    ['warranty_expires_at', (a) => a.warranty_expires_at],
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${asset.asset_code} ${asset.name}`" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm text-muted-foreground">{{ asset.asset_code }}</p>
                    <Heading :title="asset.name" :description="t(`assets.statuses.${asset.status}`)" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="can.create && !sameModel.length" variant="outline" as-child>
                        <Link :href="route('asset.assets.create', { from: asset.ulid })">{{ t('assets.add_same_model') }}</Link>
                    </Button>
                    <Button v-if="can.openTicket" variant="outline" as-child>
                        <Link :href="route('service.tickets.create', { asset: asset.ulid })">{{ t('tickets.open_for_asset') }}</Link>
                    </Button>
                    <Button v-if="can.printLabel" variant="outline" as-child>
                        <a :href="route('labeling.labels.print', { assets: asset.ulid })" target="_blank" rel="noopener">{{
                            t('labels.print_one')
                        }}</a>
                    </Button>
                    <Button v-if="can.delete" variant="outline" @click="destroy">{{ t('common.delete') }}</Button>
                    <Button v-if="can.update" as-child>
                        <Link :href="route('asset.assets.edit', asset.ulid)">{{ t('common.edit') }}</Link>
                    </Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p v-if="page.props.flash.error" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </p>

            <PhotoSlots :photos="photos" :editable="can.update" />

            <dl class="grid gap-x-6 gap-y-4 rounded-md border p-4 sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="[key, value] in details" :key="key">
                    <dt class="text-xs text-muted-foreground">{{ t(`assets.${key}`) }}</dt>
                    <dd class="text-sm">{{ value(asset) ?? t('common.none') }}</dd>
                </div>
            </dl>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('assets.specs') }}</h3>
                <dl v-if="asset.specs.length" class="grid gap-x-6 gap-y-4 rounded-md border p-4 sm:grid-cols-3 lg:grid-cols-5">
                    <div v-for="spec in asset.specs" :key="spec.label">
                        <dt class="text-xs text-muted-foreground">{{ spec.label }}</dt>
                        <dd class="text-sm">{{ spec.value ?? t('common.none') }}</dd>
                    </div>
                </dl>
                <p v-else class="text-sm text-muted-foreground">{{ t('assets.no_specs') }}</p>
            </section>

            <AssetCheckoutPanel v-if="checkouts" :asset-ulid="asset.ulid" :checkouts="checkouts" />

            <AttachmentList
                :attachments="attachments"
                :can-delete="can.update"
                :upload-url="can.update ? route('asset.assets.attachments.store', asset.ulid) : null"
            />

            <!-- Every device of this category, brand and model -->
            <section v-if="sameModel.length" class="space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <Barcode class="h-4 w-4" />
                        {{ t('assets.same_model') }}
                        <span class="rounded-md border px-2 py-0.5 text-xs font-normal">
                            {{ t('assets.same_model_count', { spare: spareCount, total: sameModel.length }) }}
                        </span>
                        <span class="text-xs font-normal text-muted-foreground">{{ t('assets.same_model_hint') }}</span>
                    </h3>
                    <Button v-if="can.create" size="sm" variant="outline" as-child>
                        <Link :href="route('asset.assets.create', { from: asset.ulid })">
                            <Plus class="h-4 w-4" />
                            {{ t('assets.add_same_model') }}
                        </Link>
                    </Button>
                </div>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('assets.serial_number') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('assets.property_no') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('assets.asset_code') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('assets.status') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('checkouts.holder') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('assets.location') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="unit in sameModel"
                                :key="unit.ulid"
                                class="border-t"
                                :class="{ 'bg-muted/40': unit.ulid === asset.ulid }"
                                :aria-current="unit.ulid === asset.ulid ? 'true' : undefined"
                            >
                                <td class="whitespace-nowrap px-4 py-2 font-mono">{{ unit.serial_number ?? '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 font-mono">{{ unit.property_no ?? '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">
                                    <span v-if="unit.ulid === asset.ulid">
                                        {{ unit.asset_code }} <span class="font-sans text-muted-foreground">({{ t('assets.this_asset') }})</span>
                                    </span>
                                    <Link
                                        v-else
                                        :href="route('asset.assets.show', unit.ulid)"
                                        class="text-primary underline-offset-4 hover:underline"
                                    >
                                        {{ unit.asset_code }}
                                    </Link>
                                </td>
                                <td class="px-4 py-2"><AssetStatusBadge :status="unit.status" /></td>
                                <td class="px-4 py-2">{{ unit.holder ?? '-' }}</td>
                                <td class="px-4 py-2">{{ [unit.branch, unit.location].filter(Boolean).join(' · ') || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="tickets !== null" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('tickets.asset_tickets') }}</h3>
                <ul v-if="tickets.length" class="divide-y rounded-md border text-sm">
                    <li v-for="ticket in tickets" :key="ticket.ulid" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                        <span>
                            <Link
                                :href="route('service.tickets.show', ticket.ulid)"
                                class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                            >
                                {{ ticket.ticket_no }}
                            </Link>
                            <span class="ml-2">{{ ticket.title }}</span>
                            <span class="ml-2 text-xs text-muted-foreground">{{ dateTime(ticket.created_at) }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <TicketPriorityBadge :priority="ticket.priority" />
                            <TicketStatusBadge :status="ticket.status" />
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">{{ t('tickets.no_asset_tickets') }}</p>
            </section>

            <section v-if="pmHistory !== null" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('pm_visits.asset_history') }}</h3>
                <ul v-if="pmHistory.length" class="divide-y rounded-md border text-sm">
                    <li v-for="entry in pmHistory" :key="entry.visit_ulid" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                        <span>
                            <Link
                                :href="route('maintenance.visits.show', entry.visit_ulid)"
                                class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                            >
                                {{ entry.visit_no }}
                            </Link>
                            <span class="ml-2 text-xs text-muted-foreground">{{ t('pm_visits.due_on') }} {{ entry.due_on }}</span>
                            <span v-if="entry.checked_at" class="ml-2 text-xs text-muted-foreground">{{ dateTime(entry.checked_at) }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <PmResultBadge :result="entry.result" />
                            <PmVisitStatusBadge :status="entry.visit_status" />
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">{{ t('pm_visits.no_asset_history') }}</p>
            </section>

            <section v-if="contracts !== null" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('contracts.asset_contracts') }}</h3>
                <ul v-if="contracts.length" class="divide-y rounded-md border text-sm">
                    <li v-for="contract in contracts" :key="contract.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                        <span>
                            <Link
                                :href="route('contract.contracts.show', contract.id)"
                                class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                            >
                                {{ contract.contract_no }}
                            </Link>
                            <span class="ml-2">{{ contract.title }}</span>
                            <span class="ml-2 text-xs text-muted-foreground"
                                >{{ contract.starts_on }} – {{ contract.ends_on }} · {{ contract.service_window }}</span
                            >
                        </span>
                        <ContractPhaseBadge :phase="contract.phase" />
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">{{ t('contracts.no_asset_contracts') }}</p>
            </section>

            <section v-if="asset.notes" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('assets.notes') }}</h3>
                <p class="whitespace-pre-line text-sm">{{ asset.notes }}</p>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('common.history') }}</h3>
                <ul v-if="history.length" class="divide-y rounded-md border text-sm">
                    <li v-for="entry in history" :key="entry.id" class="flex flex-wrap justify-between gap-2 px-4 py-2">
                        <span>
                            <span class="font-medium">{{ entry.event ? t(`assets.history_events.${entry.event}`) : t('common.none') }}</span>
                            <span v-if="entry.event === 'updated' && entry.changed.length" class="text-muted-foreground">
                                — {{ entry.changed.map(fieldLabel).join(', ') }}
                            </span>
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ t('common.by', { name: entry.actor ?? t('common.system') }) }} · {{ dateTime(entry.at) }}
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">{{ t('common.no_history') }}</p>
            </section>

            <Button variant="outline" as-child>
                <Link :href="route('asset.assets.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
