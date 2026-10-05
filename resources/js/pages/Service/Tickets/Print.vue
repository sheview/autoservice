<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { documentDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileDown, Printer } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted } from 'vue';

// The job sheet of a ticket: one A4 page printed with the browser. No app layout or navigation.
// Same layout as resources/views/documents/ticket.blade.php (the PDF): change both together.
const props = defineProps<{
    company: string | null;
    logo: string | null;
    // The customer's tracking QR (link with its token) and where to type the number instead.
    tracking: { qr: string; search: string } | null;
    ticket: {
        ulid: string;
        ticket_no: string;
        title: string;
        description: string | null;
        status: string;
        priority: string;
        source: string;
        contact_name: string | null;
        contact_phone: string | null;
        customer: string | null;
        asset: { asset_code: string; name: string } | null;
        device: {
            registered: boolean;
            name: string | null;
            brand: string | null;
            model: string | null;
            serial: string | null;
            serial_unknown: boolean;
            location: string | null;
            ip: string | null;
            property_no: string | null;
        };
        report: { cause: string | null; extra_cost: string | null; approver_name: string | null };
        department: string | null;
        warranty: { status: string | null; expires_on: string | null };
        contract_no: string | null;
        branch: string | null;
        assignee: string | null;
        reporter: string | null;
        created_at: string;
        responded_at: string | null;
        resolved_at: string | null;
        closed_at: string | null;
    };
    notes: { id: number; body: string; user_name: string | null; at: string }[];
    parts: { part_id: number; code: string; name: string; unit: string; quantity: number }[] | null;
    // The score boxes for the customer to tick; null = the survey module is off.
    rating: { score: number | null; comment: string | null } | null;
}>();

const print = () => window.print();

// @page cannot be scoped to a component, so the page size goes in a <style> of its own while this page is open.
let style: HTMLStyleElement | null = null;
onMounted(() => {
    style = document.head.appendChild(document.createElement('style'));
    style.textContent = '@page { size: A4; margin: 12mm 14mm; }';
});
onBeforeUnmount(() => style?.remove());

const blank = '.'.repeat(40);
const ticket = props.ticket;
const device = ticket.device;

const documentDate = (date: string) => new Date(date).toLocaleDateString('th-TH', { dateStyle: 'medium', timeZone: 'Asia/Bangkok' });

const warrantyText = ticket.warranty.status
    ? t(`tickets.warranty_statuses.${ticket.warranty.status}`) +
      (ticket.warranty.expires_on ? ` (${t('ticket_print.warranty_until', { date: documentDate(ticket.warranty.expires_on) })})` : '')
    : null;

const requester = [
    { label: 'ticket_print.contact_name', value: ticket.contact_name },
    { label: 'ticket_print.contact_phone', value: ticket.contact_phone },
    { label: 'ticket_print.organization', value: [ticket.customer, ticket.department].filter(Boolean).join(' · ') },
    { label: 'tickets.branch', value: ticket.branch },
    { label: 'tickets.contract', value: ticket.contract_no ?? t('tickets.out_of_contract') },
    { label: 'tickets.source', value: t(`tickets.sources.${ticket.source}`) },
    { label: 'tickets.priority', value: t(`tickets.priorities.${ticket.priority}`) },
    { label: 'tickets.assignee', value: ticket.assignee },
];

const deviceFacts = [
    { label: 'tickets.device_name', value: ticket.asset ? `${ticket.asset.asset_code} ${ticket.asset.name}` : device.name },
    {
        label: `${t('tickets.device_brand')} / ${t('tickets.device_model')}`,
        value: [device.brand, device.model].filter(Boolean).join(' / '),
        raw: true,
    },
    { label: 'ticket_print.serial', value: device.serial_unknown ? t('tickets.device_serial_unknown') : device.serial },
    { label: 'tickets.property_no', value: device.property_no },
    { label: 'ticket_print.location', value: device.location },
    { label: 'ticket_print.ip_address', value: device.ip },
];

const times = [
    { label: 'ticket_print.opened_at', value: ticket.created_at },
    { label: 'ticket_print.responded_at', value: ticket.responded_at },
    { label: 'ticket_print.resolved_at', value: ticket.resolved_at },
    { label: 'ticket_print.closed_at', value: ticket.closed_at },
];

const extraCost =
    ticket.report.extra_cost === null
        ? null
        : Number(ticket.report.extra_cost).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// The customer's side asks for the repair and approves it; at the end both sides sign it off.
const requestSigns = [
    { role: t('ticket_print.reporter_sign'), name: ticket.contact_name },
    { role: t('ticket_print.approver_sign'), name: ticket.report.approver_name },
];
const doneSigns = [
    { caption: t('ticket_print.done_caption'), role: t('ticket_print.technician'), name: ticket.assignee },
    { caption: t('ticket_print.checked_caption'), role: t('ticket_print.customer_sign'), name: ticket.contact_name },
];

// The five levels, best first, as on the paper form.
const levels = [5, 4, 3, 2, 1];

// A few empty rows to write on when nothing was recorded in the system.
const blankRows = 2;
</script>

<template>
    <Head :title="`${t('ticket_print.title')} ${ticket.ticket_no}`" />

    <div class="min-h-screen bg-muted/40 print:bg-white">
        <!-- Toolbar (not printed) -->
        <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b bg-background px-4 py-3 print:hidden">
            <Button variant="outline" as-child>
                <Link :href="route('service.tickets.show', ticket.ulid)">
                    <ArrowLeft class="h-4 w-4" />
                    {{ t('common.back') }}
                </Link>
            </Button>
            <Button @click="print">
                <Printer class="h-4 w-4" />
                {{ t('ticket_print.print_now') }}
            </Button>
            <Button variant="outline" as-child>
                <a :href="route('service.tickets.pdf', ticket.ulid)" target="_blank" rel="noopener">
                    <FileDown class="h-4 w-4" />
                    {{ t('ticket_print.pdf') }}
                </a>
            </Button>
            <span class="text-xs text-muted-foreground">{{ t('ticket_print.hint') }}</span>
        </div>

        <div class="flex justify-center p-6 print:block print:p-0">
            <article class="sheet bg-white text-[10pt] leading-snug text-black shadow print:shadow-none">
                <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-2">
                    <div class="flex items-start gap-3">
                        <img v-if="logo" :src="logo" alt="" class="h-[16mm] max-w-[40mm] object-contain" />
                        <div>
                            <p class="text-[14pt] font-bold">{{ company }}</p>
                            <p class="text-[12pt] font-semibold">{{ t('ticket_print.title') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 text-right">
                        <div>
                            <p class="font-mono text-[13pt] font-bold">{{ ticket.ticket_no }}</p>
                            <p>{{ t(`tickets.statuses.${ticket.status}`) }}</p>
                        </div>
                        <!-- Track the job: at least 2.5 cm so it scans from small paper, in black and white -->
                        <figure v-if="tracking" class="w-[28mm] text-center">
                            <div class="size-[28mm] [&>svg]:size-full" v-html="tracking.qr" />
                            <figcaption class="text-[7pt] leading-tight">{{ t('ticket_print.track_scan') }}<br />{{ tracking.search }}</figcaption>
                        </figure>
                    </div>
                </header>

                <section class="mt-2.5 grid grid-cols-4 gap-2 text-[9.5pt]">
                    <div v-for="time in times" :key="time.label" class="border border-gray-400 px-2 py-1">
                        <p class="text-gray-600">{{ t(time.label) }}</p>
                        <p>{{ documentDateTime(time.value) }}</p>
                    </div>
                </section>

                <!-- Who asked for the service, and how the job is handled -->
                <section class="mt-2.5">
                    <h2 class="mb-1 border-b border-black pb-0.5 font-semibold">{{ t('ticket_print.requester') }}</h2>
                    <div class="grid grid-cols-2 gap-x-7 gap-y-0.5">
                        <div v-for="fact in requester" :key="fact.label" class="flex gap-2">
                            <span class="w-28 shrink-0 text-gray-600">{{ t(fact.label) }}</span>
                            <span class="min-w-0 flex-1 border-b border-dotted border-gray-400">{{ fact.value || ' ' }}</span>
                        </div>
                    </div>
                </section>

                <!-- The device: from the asset register, or as told when it is not registered -->
                <section class="mt-2.5">
                    <h2 class="mb-1 border-b border-black pb-0.5 font-semibold">
                        {{ t('ticket_print.device') }}
                        <span class="text-[9pt] font-normal text-gray-600">
                            ({{ t(device.registered ? 'tickets.device_registered_badge' : 'tickets.device_unregistered_badge') }})
                        </span>
                    </h2>
                    <div class="grid grid-cols-2 gap-x-7 gap-y-0.5">
                        <div v-for="fact in deviceFacts" :key="fact.label" class="flex gap-2">
                            <span class="w-28 shrink-0 text-gray-600">{{ fact.raw ? fact.label : t(fact.label) }}</span>
                            <span class="min-w-0 flex-1 border-b border-dotted border-gray-400">{{ fact.value || ' ' }}</span>
                        </div>
                        <div class="col-span-2 flex gap-2">
                            <span class="w-28 shrink-0 text-gray-600">{{ t('ticket_print.warranty') }}</span>
                            <span class="min-w-0 flex-1 border-b border-dotted border-gray-400">
                                <template v-if="warrantyText">{{ warrantyText }}</template>
                                <template v-else>
                                    <span class="checkbox" /> {{ t('tickets.warranty_statuses.in_warranty') }} &nbsp; <span class="checkbox" />
                                    {{ t('tickets.warranty_statuses.out_of_warranty') }}
                                </template>
                            </span>
                        </div>
                    </div>
                </section>

                <section class="mt-2.5">
                    <h2 class="mb-1 border-b border-black pb-0.5 font-semibold">{{ t('ticket_print.problem') }}</h2>
                    <div class="min-h-[15mm] border border-gray-400 px-2 py-1.5">
                        <p class="font-medium">{{ ticket.title }}</p>
                        <p v-if="ticket.description" class="whitespace-pre-line">{{ ticket.description }}</p>
                    </div>
                </section>

                <!-- The customer's side asks for the repair and approves it -->
                <section class="signatures mt-3 grid grid-cols-2 gap-10 text-center">
                    <div v-for="sign in requestSigns" :key="sign.role">
                        <p class="flex h-[26px] items-end gap-1.5">
                            <span>{{ t('ticket_print.sign') }}</span>
                            <span class="flex-1 border-b border-dotted border-black" />
                            <span>{{ sign.role }}</span>
                        </p>
                        <p>( {{ sign.name ?? blank }} )</p>
                        <p class="text-gray-600">{{ t('ticket_print.date_line') }}</p>
                    </div>
                </section>

                <!-- What the technician found and did -->
                <section class="mt-2.5">
                    <h2 class="mb-1 border-b border-black pb-0.5 font-semibold">{{ t('ticket_print.staff_section') }}</h2>
                    <div class="flex gap-2">
                        <span class="w-28 shrink-0 text-gray-600">{{ t('ticket_print.cause') }}</span>
                        <span class="min-w-0 flex-1 whitespace-pre-line border-b border-dotted border-gray-400">{{
                            ticket.report.cause || ' '
                        }}</span>
                    </div>
                    <p class="mt-1 text-gray-600">{{ t('ticket_print.work_done') }}</p>
                    <div class="min-h-[26mm] border border-gray-400 px-2 py-1">
                        <p v-for="note in notes" :key="note.id" class="mb-1 whitespace-pre-line">
                            <span class="text-[9pt] text-gray-600">{{ documentDateTime(note.at) }} · {{ note.user_name ?? t('common.system') }}</span>
                            <br />
                            {{ note.body }}
                        </p>
                    </div>
                    <div class="mt-1.5 flex w-1/2 gap-2">
                        <span class="w-28 shrink-0 text-gray-600">{{ t('ticket_print.extra_cost') }}</span>
                        <span class="min-w-0 flex-1 border-b border-dotted border-gray-400 pr-1.5 text-right">{{ extraCost || ' ' }}</span>
                        <span>{{ t('ticket_print.baht') }}</span>
                    </div>
                </section>

                <section class="mt-2.5">
                    <h2 class="mb-1 border-b border-black pb-0.5 font-semibold">{{ t('ticket_print.parts') }}</h2>
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-gray-100 text-left">
                                <th class="w-10 border border-gray-400 px-2 py-0.5 text-center font-medium">{{ t('ticket_print.no') }}</th>
                                <th class="w-28 border border-gray-400 px-2 py-0.5 font-medium">{{ t('ticket_print.part_code') }}</th>
                                <th class="border border-gray-400 px-2 py-0.5 font-medium">{{ t('ticket_print.part_name') }}</th>
                                <th class="w-40 border border-gray-400 px-2 py-0.5 font-medium">{{ t('ticket_print.part_serial') }}</th>
                                <th class="w-20 border border-gray-400 px-2 py-0.5 text-right font-medium">{{ t('ticket_print.quantity') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(part, index) in parts ?? []" :key="part.part_id">
                                <td class="border border-gray-400 px-2 py-0.5 text-center">{{ index + 1 }}</td>
                                <td class="border border-gray-400 px-2 py-0.5 font-mono text-[9.5pt]">{{ part.code }}</td>
                                <td class="border border-gray-400 px-2 py-1">{{ part.name }}</td>
                                <td class="border border-gray-400 px-2 py-1" />
                                <td class="border border-gray-400 px-2 py-0.5 text-right">{{ part.quantity }} {{ part.unit }}</td>
                            </tr>
                            <template v-if="!parts?.length">
                                <tr v-for="row in blankRows" :key="`blank-${row}`">
                                    <td v-for="cell in 5" :key="cell" class="h-6 border border-gray-400" />
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </section>

                <!-- The technician says it is done, the customer that the device came back right -->
                <section class="signatures mt-3 grid grid-cols-2 gap-10 text-center">
                    <div v-for="sign in doneSigns" :key="sign.role">
                        <p class="font-semibold">{{ sign.caption }}</p>
                        <p class="flex h-[26px] items-end gap-1.5">
                            <span>{{ t('ticket_print.sign') }}</span>
                            <span class="flex-1 border-b border-dotted border-black" />
                            <span>{{ sign.role }}</span>
                        </p>
                        <p>( {{ sign.name ?? blank }} )</p>
                        <p class="text-gray-600">{{ t('ticket_print.date_line') }}</p>
                    </div>
                </section>

                <section v-if="rating !== null" class="rating mt-2.5">
                    <h2 class="mb-1 border-b border-black pb-0.5 font-semibold">{{ t('ticket_print.rating') }}</h2>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-0.5">
                        <p v-for="score in levels" :key="score">
                            <span class="checkbox">{{ rating.score === score ? '✓' : '' }}</span>
                            {{ t('ticket_survey.level_score', { score }) }} :
                            <strong>{{ t(`ticket_survey.levels.${score}.label`) }}</strong>
                            <span class="text-gray-600"> ({{ t(`ticket_survey.levels.${score}.hint`) }})</span>
                        </p>
                    </div>
                    <div class="mt-1.5 flex gap-2">
                        <span class="w-28 shrink-0 text-gray-600">{{ t('ticket_print.rating_comment') }}</span>
                        <span class="min-w-0 flex-1 border-b border-dotted border-gray-400">{{ rating.comment || ' ' }}</span>
                    </div>
                </section>

                <footer class="mt-4 text-[8.5pt] text-gray-500">
                    {{ t('ticket_print.printed_at', { at: documentDateTime(new Date().toISOString()) }) }}
                </footer>
            </article>
        </div>
    </div>
</template>

<style scoped>
/* A4 with the margins inside the sheet, so the preview looks like the paper. */
.sheet {
    width: 210mm;
    min-height: 297mm;
    padding: 12mm 14mm;
}

.checkbox {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 1px solid #000;
    text-align: center;
    line-height: 11px;
    font-size: 9pt;
    vertical-align: -1px;
}

@media print {
    .sheet {
        width: auto;
        min-height: 0;
        padding: 0;
    }
    .signatures,
    .rating,
    tr {
        break-inside: avoid;
    }
}
</style>
