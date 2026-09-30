<script setup lang="ts">
import PmResultBadge from '@/components/PmResultBadge.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, Wrench } from 'lucide-vue-next';

const props = defineProps<{
    asset: {
        ulid: string;
        asset_code: string;
        name: string;
        category: string | null;
        branch: string | null;
        status: string;
        serial_number: string | null;
        customer: string | null;
    };
    tickets: { ulid: string; ticket_no: string; title: string; status: string; created_at: string }[] | null;
    lastPm: { visit_ulid: string; visit_no: string; result: string; due_on: string; checked_at: string | null } | null;
    can: { openTicket: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: props.asset.asset_code, href: route('labeling.scan', props.asset.ulid) }];

const facts = [
    { label: 'scan.customer', value: props.asset.customer },
    { label: 'scan.category', value: props.asset.category },
    { label: 'scan.branch', value: props.asset.branch },
    { label: 'scan.serial', value: props.asset.serial_number },
].filter((fact) => fact.value);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${asset.asset_code} ${asset.name}`" />

        <div class="mx-auto w-full max-w-lg space-y-5 p-4">
            <div class="rounded-xl border p-5">
                <p class="font-mono text-sm text-muted-foreground">{{ asset.asset_code }}</p>
                <h1 class="text-xl font-semibold">{{ asset.name }}</h1>
                <p class="text-sm text-muted-foreground">{{ t(`assets.statuses.${asset.status}`) }}</p>

                <dl v-if="facts.length" class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div v-for="fact in facts" :key="fact.label">
                        <dt class="text-xs text-muted-foreground">{{ t(fact.label) }}</dt>
                        <dd>{{ fact.value }}</dd>
                    </div>
                </dl>
            </div>

            <div class="grid gap-3">
                <Button v-if="can.openTicket" size="lg" class="h-12 text-base" as-child>
                    <Link :href="route('service.tickets.create', { asset: asset.ulid })">
                        <Wrench class="h-5 w-5" />
                        {{ t('scan.report') }}
                    </Link>
                </Button>
                <Button variant="outline" size="lg" as-child>
                    <Link :href="route('asset.assets.show', asset.ulid)">
                        <ExternalLink class="h-4 w-4" />
                        {{ t('scan.details') }}
                    </Link>
                </Button>
            </div>

            <section v-if="tickets !== null" class="space-y-2">
                <h2 class="text-sm font-semibold">{{ t('scan.recent_tickets') }}</h2>
                <ul v-if="tickets.length" class="divide-y rounded-md border text-sm">
                    <li v-for="ticket in tickets" :key="ticket.ulid">
                        <Link
                            :href="route('service.tickets.show', ticket.ulid)"
                            class="flex items-center justify-between gap-2 px-4 py-3 hover:bg-muted/40"
                        >
                            <span class="min-w-0">
                                <span class="block truncate">{{ ticket.title }}</span>
                                <span class="font-mono text-xs text-muted-foreground"
                                    >{{ ticket.ticket_no }} · {{ dateTime(ticket.created_at) }}</span
                                >
                            </span>
                            <TicketStatusBadge :status="ticket.status" />
                        </Link>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">{{ t('scan.no_tickets') }}</p>
            </section>

            <section v-if="lastPm !== undefined && lastPm !== null" class="space-y-2">
                <h2 class="text-sm font-semibold">{{ t('scan.last_pm') }}</h2>
                <Link
                    :href="route('maintenance.visits.show', lastPm.visit_ulid)"
                    class="flex items-center justify-between rounded-md border px-4 py-3 text-sm hover:bg-muted/40"
                >
                    <span>
                        <span class="font-mono text-xs">{{ lastPm.visit_no }}</span>
                        <span class="ml-2 text-xs text-muted-foreground">{{ lastPm.checked_at ? dateTime(lastPm.checked_at) : lastPm.due_on }}</span>
                    </span>
                    <PmResultBadge :result="lastPm.result" />
                </Link>
            </section>
        </div>
    </AppLayout>
</template>
