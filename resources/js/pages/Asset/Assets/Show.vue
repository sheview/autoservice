<script setup lang="ts">
import ContractPhaseBadge from '@/components/ContractPhaseBadge.vue';
import Heading from '@/components/Heading.vue';
import TicketPriorityBadge from '@/components/TicketPriorityBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';

interface AssetDetail {
    ulid: string;
    asset_code: string;
    name: string;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    status: string;
    location: string | null;
    notes: string | null;
    category: string | null;
    branch: string | null;
    customer: string | null;
    purchased_at: string | null;
    purchase_price: string | null;
    warranty_expires_at: string | null;
    specs: { label: string; value: string | number | null }[];
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
    history: HistoryEntry[];
    contracts: AssetContract[] | null;
    tickets: { ulid: string; ticket_no: string; title: string; status: string; priority: string; created_at: string }[] | null;
    can: { update: boolean; delete: boolean; openTicket: boolean };
}>();

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
    ['customer', (a) => a.customer],
    ['location', (a) => a.location],
    ['brand', (a) => a.brand],
    ['model', (a) => a.model],
    ['serial_number', (a) => a.serial_number],
    ['purchased_at', (a) => a.purchased_at],
    ['purchase_price', (a) => money(a.purchase_price)],
    ['warranty_expires_at', (a) => a.warranty_expires_at],
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${asset.asset_code} ${asset.name}`" />

        <div class="max-w-4xl space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm text-muted-foreground">{{ asset.asset_code }}</p>
                    <Heading :title="asset.name" :description="t(`assets.statuses.${asset.status}`)" />
                </div>
                <div class="flex gap-2">
                    <Button v-if="can.openTicket" variant="outline" as-child>
                        <Link :href="route('service.tickets.create', { asset: asset.ulid })">{{ t('tickets.open_for_asset') }}</Link>
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

            <dl class="grid gap-x-6 gap-y-4 rounded-md border p-4 sm:grid-cols-3">
                <div v-for="[key, value] in details" :key="key">
                    <dt class="text-xs text-muted-foreground">{{ t(`assets.${key}`) }}</dt>
                    <dd class="text-sm">{{ value(asset) ?? t('common.none') }}</dd>
                </div>
            </dl>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('assets.specs') }}</h3>
                <dl v-if="asset.specs.length" class="grid gap-x-6 gap-y-4 rounded-md border p-4 sm:grid-cols-3">
                    <div v-for="spec in asset.specs" :key="spec.label">
                        <dt class="text-xs text-muted-foreground">{{ spec.label }}</dt>
                        <dd class="text-sm">{{ spec.value ?? t('common.none') }}</dd>
                    </div>
                </dl>
                <p v-else class="text-sm text-muted-foreground">{{ t('assets.no_specs') }}</p>
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
