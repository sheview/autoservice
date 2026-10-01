<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';

interface Label {
    ulid: string;
    asset_code: string;
    name: string;
    category: string | null;
    serial_number: string | null;
    property_no: string | null;
    brand: string | null;
    model: string | null;
    customer: string | null;
    province: string | null;
    // Detailed labels: the contract covering the device (null = none, or a small label).
    contract: { title: string; contract_no: string; period: string } | null;
    qr: string; // inline SVG made by the server
}

interface Template {
    key: string;
    type: 'roll' | 'sheet';
    width: number;
    height: number;
    cols?: number;
    rows?: number;
    detailed: boolean;
    paper?: { size: string; width: number; height: number };
}

const props = defineProps<{
    labels: Label[];
    template: string;
    templates: Template[];
    company: { name: string; service_phone: string | null; service_email: string | null; logo_url: string | null } | null;
    canEditCompany: boolean;
}>();

const current = computed(() => props.templates.find((tpl) => tpl.key === props.template) ?? props.templates[0]);

// Sheets: labels in pages of cols x rows. Rolls: every label is its own page.
const pages = computed(() => {
    const perPage = current.value.type === 'sheet' ? (current.value.cols ?? 1) * (current.value.rows ?? 1) : 1;
    const result: Label[][] = [];
    for (let i = 0; i < props.labels.length; i += perPage) result.push(props.labels.slice(i, i + perPage));
    return result;
});

// @page cannot be set per element, so the page size goes in a <style> of its own.
let style: HTMLStyleElement | null = null;
const applyPageStyle = () => {
    const tpl = current.value;
    const paper = tpl.paper ?? { size: 'A4', width: 210, height: 297 };
    // A sheet: the labels centred on the paper.
    const page =
        tpl.type === 'roll'
            ? `size: ${tpl.width}mm ${tpl.height}mm; margin: 0;`
            : `size: ${paper.size}; margin: ${(paper.height - tpl.height * (tpl.rows ?? 1)) / 2}mm ${(paper.width - tpl.width * (tpl.cols ?? 1)) / 2}mm;`;
    style ??= document.head.appendChild(document.createElement('style'));
    style.textContent = `@page { ${page} }`;
};
onMounted(applyPageStyle);
watch(current, applyPageStyle);
onBeforeUnmount(() => style?.remove());

const changeTemplate = (event: Event) =>
    router.get(
        route('labeling.labels.print'),
        { assets: props.labels.map((l) => l.ulid).join(','), template: (event.target as HTMLSelectElement).value },
        { preserveScroll: true },
    );

// Log the print, then open the print dialog.
const print = () =>
    router.post(
        route('labeling.labels.record'),
        { assets: props.labels.map((l) => l.ulid), template: props.template },
        { preserveState: true, preserveScroll: true, onFinish: () => window.print() },
    );

const labelStyle = computed(() => ({ width: `${current.value.width}mm`, height: `${current.value.height}mm` }));
const gridStyle = computed(() =>
    current.value.type === 'sheet' ? { gridTemplateColumns: `repeat(${current.value.cols}, ${current.value.width}mm)` } : {},
);
// Bigger labels get bigger text; the QR is as tall as the label allows.
const textScale = computed(() => Math.max(0.75, Math.min(1.25, current.value.height / 30)));

// A detailed label without the company's logo, phone and e-mail misses its header.
const companyIncomplete = computed(
    () => current.value.detailed && !(props.company?.logo_url && props.company.service_phone && props.company.service_email),
);
</script>

<template>
    <Head :title="t('labels.title')" />

    <div class="min-h-screen bg-muted/40 print:bg-white">
        <!-- Toolbar (not printed) -->
        <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b bg-background px-4 py-3 print:hidden">
            <span class="font-semibold">{{ t('labels.preview', { count: labels.length }) }}</span>
            <select
                :value="template"
                class="shadow-xs h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                :aria-label="t('labels.template')"
                @change="changeTemplate"
            >
                <option v-for="tpl in templates" :key="tpl.key" :value="tpl.key">{{ t(`labels.templates.${tpl.key}`) }}</option>
            </select>
            <Button @click="print">
                <Printer class="h-4 w-4" />
                {{ t('labels.print_now') }}
            </Button>
            <span class="text-xs text-muted-foreground">{{ t('labels.print_hint') }}</span>
            <span v-if="companyIncomplete" class="text-xs text-amber-700 dark:text-amber-400">
                {{ t('labels.no_company_info') }}
                <Link v-if="canEditCompany" :href="route('tenancy.company.edit')" class="underline underline-offset-4">{{
                    t('labels.set_company_info')
                }}</Link>
            </span>
        </div>

        <div class="flex flex-col items-center gap-6 p-6 print:block print:p-0">
            <div
                v-for="(labelsOfPage, p) in pages"
                :key="p"
                class="label-page bg-white shadow print:shadow-none"
                :class="current.type === 'sheet' ? 'grid' : ''"
                :style="gridStyle"
            >
                <!-- Detailed: company header, contract, device, QR (like a device sticker) -->
                <template v-if="current.detailed">
                    <div
                        v-for="label in labelsOfPage"
                        :key="label.ulid"
                        class="label flex flex-col overflow-hidden px-[2mm] py-[1.5mm] leading-[1.2] text-black"
                        :class="current.type === 'sheet' ? 'outline-dashed outline-1 outline-gray-200 print:outline-none' : ''"
                        :style="labelStyle"
                    >
                        <div class="flex shrink-0 items-center gap-[2mm] border-b-[0.3mm] border-black pb-[0.8mm]">
                            <img v-if="company?.logo_url" :src="company.logo_url" alt="" class="h-[10mm] max-w-[28mm] shrink-0 object-contain" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-[9.5pt] font-bold">{{ company?.name }}</div>
                                <div v-if="company?.service_phone" class="truncate text-[7.5pt]">
                                    {{ t('labels.service_phone', { phone: company.service_phone }) }}
                                </div>
                                <div v-if="company?.service_email" class="truncate text-[7.5pt]">
                                    {{ t('labels.service_email', { email: company.service_email }) }}
                                </div>
                            </div>
                        </div>

                        <div v-if="label.contract" class="mt-[0.8mm] shrink-0 text-center">
                            <div class="line-clamp-2 text-[6.5pt] italic">
                                {{ label.contract.title }} {{ t('labels.contract_no', { no: label.contract.contract_no }) }}
                            </div>
                            <div class="truncate text-[8pt] font-bold">{{ label.contract.period }}</div>
                        </div>

                        <!-- The device: takes the rest of the label, lines spread over its height, QR as big as fits -->
                        <div class="mt-[1mm] flex min-h-0 flex-1 items-stretch gap-[1.5mm]">
                            <div class="flex min-w-0 flex-1 flex-col justify-evenly text-[8pt]">
                                <div class="truncate">{{ t('labels.equipment') }} : {{ label.name }}</div>
                                <div v-if="label.brand || label.model" class="truncate">
                                    <span v-if="label.brand" class="mr-[3mm]">{{ t('labels.brand') }} : {{ label.brand }}</span>
                                    <span v-if="label.model">{{ t('labels.model') }} : {{ label.model }}</span>
                                </div>
                                <div class="truncate text-[9pt] font-bold">{{ t('labels.serial') }} : {{ label.serial_number ?? '-' }}</div>
                                <div v-if="label.property_no" class="truncate">{{ t('assets.property_no') }} : {{ label.property_no }}</div>
                                <!-- A long unit name is shortened; the province is always shown in full. -->
                                <div v-if="label.customer || label.province" class="flex gap-[3mm] whitespace-nowrap">
                                    <span v-if="label.customer" class="min-w-0 truncate">{{ t('labels.unit') }} : {{ label.customer }}</span>
                                    <span v-if="label.province" class="shrink-0">{{ t('labels.province') }} : {{ label.province }}</span>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-col items-center justify-end">
                                <div class="qr aspect-square h-full max-h-[22mm] min-h-0" v-html="label.qr" />
                                <div class="font-mono text-[5.5pt]">{{ label.asset_code }}</div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Small asset tag -->
                <template v-else>
                    <div
                        v-for="label in labelsOfPage"
                        :key="label.ulid"
                        class="label flex items-center gap-[1.5mm] overflow-hidden p-[1.5mm] text-black"
                        :class="current.type === 'sheet' ? 'outline-dashed outline-1 outline-gray-200 print:outline-none' : ''"
                        :style="labelStyle"
                    >
                        <div class="qr aspect-square h-full shrink-0" v-html="label.qr" />
                        <div class="flex min-w-0 flex-1 flex-col justify-center leading-tight" :style="{ fontSize: `${textScale * 7}pt` }">
                            <div v-if="company" class="truncate text-[0.8em] text-gray-600">{{ company.name }}</div>
                            <div class="truncate font-mono text-[1.35em] font-bold">{{ label.asset_code }}</div>
                            <div v-if="label.property_no" class="truncate font-mono text-[0.9em]">
                                {{ t('assets.property_no_short') }} {{ label.property_no }}
                            </div>
                            <div class="line-clamp-2 text-[1em]">{{ label.name }}</div>
                            <div v-if="label.customer" class="truncate text-[0.8em] text-gray-600">{{ label.customer }}</div>
                            <div class="mt-auto truncate text-[0.75em] font-semibold">{{ t('labels.scan_hint') }}</div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.qr :deep(svg) {
    width: 100%;
    height: 100%;
}
.qr :deep(svg .dark) {
    fill: #000;
}

@media print {
    .label-page {
        break-after: page;
    }
    .label-page:last-child {
        break-after: auto;
    }
}
</style>
