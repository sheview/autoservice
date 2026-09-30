<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { documentDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted } from 'vue';

// The job sheet of a ticket: one A4 page printed with the browser. No app layout or navigation.
const props = defineProps<{
    company: string | null;
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
}>();

const print = () => window.print();

// @page cannot be scoped to a component, so the page size goes in a <style> of its own while this page is open.
let style: HTMLStyleElement | null = null;
onMounted(() => {
    style = document.head.appendChild(document.createElement('style'));
    style.textContent = '@page { size: A4; margin: 15mm; }';
});
onBeforeUnmount(() => style?.remove());

const facts = [
    { label: 'tickets.customer', value: props.ticket.customer },
    { label: 'tickets.contact_name', value: [props.ticket.contact_name, props.ticket.contact_phone].filter(Boolean).join(' · ') },
    { label: 'tickets.asset', value: props.ticket.asset ? `${props.ticket.asset.asset_code} ${props.ticket.asset.name}` : null },
    { label: 'tickets.contract', value: props.ticket.contract_no ?? t('tickets.out_of_contract') },
    { label: 'tickets.priority', value: t(`tickets.priorities.${props.ticket.priority}`) },
    { label: 'tickets.source', value: t(`tickets.sources.${props.ticket.source}`) },
    { label: 'tickets.assignee', value: props.ticket.assignee },
    { label: 'tickets.branch', value: props.ticket.branch },
];

const times = [
    { label: 'ticket_print.opened_at', value: props.ticket.created_at },
    { label: 'ticket_print.responded_at', value: props.ticket.responded_at },
    { label: 'ticket_print.resolved_at', value: props.ticket.resolved_at },
    { label: 'ticket_print.closed_at', value: props.ticket.closed_at },
];

// A few empty rows to write on when nothing was recorded in the system.
const blankRows = 3;
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
            <span class="text-xs text-muted-foreground">{{ t('ticket_print.hint') }}</span>
        </div>

        <div class="flex justify-center p-6 print:block print:p-0">
            <article class="sheet bg-white text-[10.5pt] leading-snug text-black shadow print:shadow-none">
                <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-3">
                    <div>
                        <p class="text-[14pt] font-bold">{{ company }}</p>
                        <p class="text-[12pt] font-semibold">{{ t('ticket_print.title') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="font-mono text-[13pt] font-bold">{{ ticket.ticket_no }}</p>
                        <p>{{ t(`tickets.statuses.${ticket.status}`) }}</p>
                    </div>
                </header>

                <section class="mt-4 grid grid-cols-2 gap-x-8 gap-y-1.5">
                    <div v-for="fact in facts" :key="fact.label" class="flex gap-2">
                        <span class="w-24 shrink-0 text-gray-600">{{ t(fact.label) }}</span>
                        <span class="min-w-0 flex-1 border-b border-dotted border-gray-400">{{ fact.value || ' ' }}</span>
                    </div>
                </section>

                <section class="mt-3 grid grid-cols-4 gap-2 text-[9.5pt]">
                    <div v-for="time in times" :key="time.label" class="border border-gray-400 px-2 py-1">
                        <p class="text-gray-600">{{ t(time.label) }}</p>
                        <p>{{ documentDateTime(time.value) }}</p>
                    </div>
                </section>

                <section class="mt-4">
                    <h2 class="font-semibold">{{ t('ticket_print.problem') }}</h2>
                    <div class="mt-1 min-h-[22mm] border border-gray-400 px-2 py-1.5">
                        <p class="font-medium">{{ ticket.title }}</p>
                        <p v-if="ticket.description" class="whitespace-pre-line">{{ ticket.description }}</p>
                    </div>
                </section>

                <section class="mt-4">
                    <h2 class="font-semibold">{{ t('ticket_print.work_done') }}</h2>
                    <div class="mt-1 min-h-[38mm] border border-gray-400 px-2 py-1.5">
                        <p v-for="note in notes" :key="note.id" class="mb-1 whitespace-pre-line">
                            <span class="text-[9pt] text-gray-600">{{ documentDateTime(note.at) }} · {{ note.user_name ?? t('common.system') }}</span>
                            <br />
                            {{ note.body }}
                        </p>
                    </div>
                </section>

                <section v-if="parts !== null" class="mt-4">
                    <h2 class="font-semibold">{{ t('ticket_parts.title') }}</h2>
                    <table class="mt-1 w-full border-collapse">
                        <thead>
                            <tr class="text-left">
                                <th class="w-10 border border-gray-400 px-2 py-1 text-center font-medium">{{ t('ticket_print.no') }}</th>
                                <th class="w-36 border border-gray-400 px-2 py-1 font-medium">{{ t('parts.code') }}</th>
                                <th class="border border-gray-400 px-2 py-1 font-medium">{{ t('parts.name') }}</th>
                                <th class="w-28 border border-gray-400 px-2 py-1 text-right font-medium">{{ t('ticket_parts.quantity') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(part, index) in parts" :key="part.part_id">
                                <td class="border border-gray-400 px-2 py-1 text-center">{{ index + 1 }}</td>
                                <td class="border border-gray-400 px-2 py-1 font-mono text-[9.5pt]">{{ part.code }}</td>
                                <td class="border border-gray-400 px-2 py-1">{{ part.name }}</td>
                                <td class="border border-gray-400 px-2 py-1 text-right">{{ part.quantity }} {{ part.unit }}</td>
                            </tr>
                            <template v-if="parts.length === 0">
                                <tr v-for="row in blankRows" :key="`blank-${row}`">
                                    <td v-for="cell in 4" :key="cell" class="h-7 border border-gray-400" />
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </section>

                <section class="signatures mt-10 grid grid-cols-2 gap-12 text-center">
                    <div>
                        <p class="mx-6 border-b border-black pb-10" />
                        <p class="mt-1">( {{ ticket.assignee ?? '................................................' }} )</p>
                        <p class="font-medium">{{ t('ticket_print.technician') }}</p>
                        <p class="text-gray-600">{{ t('ticket_print.date_line') }}</p>
                    </div>
                    <div>
                        <p class="mx-6 border-b border-black pb-10" />
                        <p class="mt-1">( {{ ticket.contact_name ?? '................................................' }} )</p>
                        <p class="font-medium">{{ t('ticket_print.customer_sign') }}</p>
                        <p class="text-gray-600">{{ t('ticket_print.date_line') }}</p>
                    </div>
                </section>

                <footer class="mt-6 text-[8.5pt] text-gray-500">
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
    padding: 15mm;
}

@media print {
    .sheet {
        width: auto;
        min-height: 0;
        padding: 0;
    }
    .signatures {
        break-inside: avoid;
    }
}
</style>
