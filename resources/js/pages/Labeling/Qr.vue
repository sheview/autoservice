<script setup lang="ts">
import AssetStatusBadge from '@/components/AssetStatusBadge.vue';
import InputError from '@/components/InputError.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeftRight, CircleCheck, MapPin, Package, Search, ShieldAlert, Wrench } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface TicketRow {
    ulid: string;
    ticket_no: string;
    title: string;
    status: string;
    priority: string;
    created_at: string;
}

interface AssetCard {
    id: number;
    ulid: string;
    asset_code: string;
    name: string;
    brand: string | null;
    model: string | null;
    status: string;
    used_by: string | null;
    department: string | null;
    location: string | null;
    branch_id: number | null;
    branch: string | null;
    category: string | null;
    serials: string[];
    customer: string | null;
    contracts: { contract_no: string; title: string; phase: string; covering: boolean }[];
    warranty: 'none' | 'active' | 'expiring' | 'expired';
    warranty_expires_at: string | null;
    quantity: number;
    available: number;
    unit: string | null;
    model_units: number;
    model_available: number;
}

/**
 * An asset's QR page for staff, made for a phone on site: what the device is, its jobs, and big
 * buttons for what is done next. Opened from the label (/q/{code}) by someone signed in.
 */
const props = defineProps<{
    code: string;
    asset: AssetCard | null;
    search?: string;
    openTickets?: TicketRow[];
    history?: TicketRow[];
    branches?: { id: number; name: string }[];
    can?: { openTicket: boolean; issueParts: boolean; checkout: boolean; move: boolean };
}>();

const page = usePage<SharedData>();
const current = computed(() => props.openTickets?.[0] ?? null);
const date = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('th-TH-u-ca-gregory', { dateStyle: 'medium' }) : '');
const warrantyClass: Record<string, string> = {
    none: 'bg-muted text-muted-foreground',
    active: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    expiring: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
    expired: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};

const moving = ref(false);
const move = useForm({ branch_id: props.asset?.branch_id ?? null, location: props.asset?.location ?? '' });
const saveMove = () =>
    props.asset && move.post(route('asset.assets.move', props.asset.ulid), { preserveScroll: true, onSuccess: () => (moving.value = false) });

// Thumb-sized buttons: at least 48px high, full width on a phone.
const big = 'flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl px-4 text-base font-semibold transition active:scale-[0.98]';
</script>

<template>
    <AppLayout>
        <Head :title="asset ? `${asset.asset_code} ${asset.name}` : code" />

        <div class="mx-auto max-w-xl space-y-4 p-4">
            <p v-if="page.props.flash.success" class="rounded-xl bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <!-- Not found -->
            <template v-if="!asset">
                <div class="space-y-4 rounded-2xl border p-6 text-center">
                    <ShieldAlert class="mx-auto size-10 text-muted-foreground" />
                    <p>{{ t('qr.not_found', { code }) }}</p>
                    <Link :href="search ?? route('asset.assets.index')" :class="[big, 'bg-primary text-primary-foreground']">
                        <Search class="size-5" />{{ t('qr.search') }}
                    </Link>
                </div>
            </template>

            <template v-else>
                <!-- The device -->
                <section class="space-y-3 rounded-2xl border p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-mono text-sm text-muted-foreground">{{ asset.asset_code }}</div>
                            <h1 class="text-xl font-semibold">{{ asset.name }}</h1>
                            <div class="text-sm text-muted-foreground">
                                {{ [asset.category, asset.brand, asset.model].filter(Boolean).join(' · ') }}
                            </div>
                        </div>
                        <AssetStatusBadge :status="asset.status" />
                    </div>

                    <div class="rounded-xl px-3 py-2 text-sm font-semibold" :class="warrantyClass[asset.warranty]">
                        {{ t(`qr.warranty_states.${asset.warranty}`, { date: date(asset.warranty_expires_at) }) }}
                    </div>

                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                        <template v-if="asset.serials.length">
                            <dt class="text-muted-foreground">{{ t('qr.serial') }}</dt>
                            <dd class="font-mono">{{ asset.serials.join(', ') }}</dd>
                        </template>
                        <template v-if="asset.used_by">
                            <dt class="text-muted-foreground">{{ t('qr.used_by') }}</dt>
                            <dd>{{ [asset.used_by, asset.department].filter(Boolean).join(' · ') }}</dd>
                        </template>
                        <dt class="text-muted-foreground">{{ t('qr.location') }}</dt>
                        <dd>{{ [asset.branch, asset.location].filter(Boolean).join(' · ') || '-' }}</dd>
                        <template v-if="asset.customer">
                            <dt class="text-muted-foreground">{{ t('qr.customer') }}</dt>
                            <dd>{{ asset.customer }}</dd>
                        </template>
                        <dt class="text-muted-foreground">{{ t('qr.contract') }}</dt>
                        <dd>
                            <template v-if="asset.contracts.length">
                                <span v-for="c in asset.contracts" :key="c.contract_no" class="block">{{ c.contract_no }} {{ c.title }}</span>
                            </template>
                            <span v-else class="text-amber-700 dark:text-amber-400">{{ t('qr.no_contract') }}</span>
                        </dd>
                    </dl>

                    <div class="flex flex-wrap gap-x-4 gap-y-1 border-t pt-3 text-sm text-muted-foreground">
                        <span>{{ t('qr.this_unit', { available: asset.available, quantity: asset.quantity }) }} {{ asset.unit ?? '' }}</span>
                        <span v-if="asset.model_units > 1">{{
                            t('qr.model_units', { available: asset.model_available, total: asset.model_units })
                        }}</span>
                    </div>
                </section>

                <!-- What to do next -->
                <section class="space-y-3">
                    <Link
                        v-if="current"
                        :href="route('service.tickets.show', current.ulid)"
                        :class="[big, 'bg-primary text-primary-foreground shadow-lg']"
                    >
                        <CircleCheck class="size-6" />{{ t('qr.continue') }}
                        <span class="font-mono text-sm opacity-80">{{ current.ticket_no }}</span>
                    </Link>
                    <div class="grid grid-cols-2 gap-3">
                        <Link
                            v-if="can?.openTicket"
                            :href="route('service.tickets.create', { asset: asset.ulid })"
                            :class="[big, current ? 'border bg-background' : 'bg-primary text-primary-foreground']"
                        >
                            <Wrench class="size-5" />{{ t('qr.open_ticket') }}
                        </Link>
                        <Link
                            v-if="can?.issueParts && current"
                            :href="`${route('service.tickets.show', current.ulid)}#parts`"
                            :class="[big, 'border bg-background']"
                        >
                            <Package class="size-5" />{{ t('qr.issue_parts') }}
                        </Link>
                        <button
                            v-else-if="can?.issueParts"
                            type="button"
                            disabled
                            :class="[big, 'border bg-muted text-muted-foreground']"
                            :title="t('qr.issue_parts_hint')"
                        >
                            <Package class="size-5" />{{ t('qr.issue_parts') }}
                        </button>
                        <Link
                            v-if="can?.checkout"
                            :href="route('asset.requests.create', { asset: asset.ulid })"
                            :class="[big, 'border bg-background']"
                        >
                            <ArrowLeftRight class="size-5" />{{ t('qr.checkout') }}
                        </Link>
                        <button v-if="can?.move" type="button" :class="[big, 'border bg-background']" @click="moving = !moving">
                            <MapPin class="size-5" />{{ t('qr.move') }}
                        </button>
                    </div>
                    <p v-if="can?.issueParts && !current" class="text-xs text-muted-foreground">{{ t('qr.issue_parts_hint') }}</p>

                    <form v-if="moving" class="space-y-3 rounded-2xl border p-4" @submit.prevent="saveMove">
                        <label class="block space-y-1.5 text-sm">
                            <span class="font-semibold">{{ t('qr.branch') }}</span>
                            <select v-model="move.branch_id" class="h-12 w-full rounded-xl border border-input bg-transparent px-3 text-base">
                                <option :value="null">{{ t('qr.no_branch') }}</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </label>
                        <label class="block space-y-1.5 text-sm">
                            <span class="font-semibold">{{ t('qr.location') }}</span>
                            <input v-model="move.location" class="h-12 w-full rounded-xl border border-input bg-transparent px-3 text-base" />
                        </label>
                        <InputError :message="move.errors.branch_id || move.errors.location" />
                        <button type="submit" :disabled="move.processing" :class="[big, 'bg-primary text-primary-foreground']">
                            {{ t('qr.move_save') }}
                        </button>
                    </form>
                </section>

                <!-- Jobs -->
                <section v-if="openTickets?.length" class="space-y-2">
                    <h2 class="font-semibold">{{ t('qr.open_tickets') }}</h2>
                    <Link
                        v-for="ticket in openTickets"
                        :key="ticket.ulid"
                        :href="route('service.tickets.show', ticket.ulid)"
                        class="flex min-h-14 items-center justify-between gap-3 rounded-xl border px-4 py-2"
                    >
                        <span class="min-w-0">
                            <span class="block font-mono text-xs text-muted-foreground">{{ ticket.ticket_no }}</span>
                            <span class="block truncate">{{ ticket.title }}</span>
                        </span>
                        <TicketStatusBadge :status="ticket.status" />
                    </Link>
                </section>
                <section class="space-y-2">
                    <h2 class="font-semibold">{{ t('qr.history') }}</h2>
                    <p v-if="!history?.length" class="text-sm text-muted-foreground">{{ t('qr.no_history') }}</p>
                    <Link
                        v-for="ticket in history"
                        :key="ticket.ulid"
                        :href="route('service.tickets.show', ticket.ulid)"
                        class="flex min-h-12 items-center justify-between gap-3 rounded-xl border px-4 py-2 text-sm"
                    >
                        <span class="min-w-0">
                            <span class="block font-mono text-xs text-muted-foreground"
                                >{{ ticket.ticket_no }} · {{ date(ticket.created_at.slice(0, 10)) }}</span
                            >
                            <span class="block truncate">{{ ticket.title }}</span>
                        </span>
                        <TicketStatusBadge :status="ticket.status" />
                    </Link>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
