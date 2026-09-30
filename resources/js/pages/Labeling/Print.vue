<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { Head, router } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';

interface Label {
    ulid: string;
    asset_code: string;
    name: string;
    category: string | null;
    serial_number: string | null;
    customer: string | null;
    qr: string; // inline SVG made by the server
}

interface Template {
    key: string;
    type: 'roll' | 'sheet';
    width: number;
    height: number;
    cols?: number;
    rows?: number;
}

const props = defineProps<{
    labels: Label[];
    template: string;
    templates: Template[];
    company: string | null;
}>();

const current = computed(() => props.templates.find((tpl) => tpl.key === props.template) ?? props.templates[0]);

// A4 sheets: labels in pages of cols x rows. Rolls: every label is its own page.
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
    const page =
        tpl.type === 'roll'
            ? `size: ${tpl.width}mm ${tpl.height}mm; margin: 0;`
            : `size: A4; margin: ${(297 - tpl.height * (tpl.rows ?? 1)) / 2}mm ${(210 - tpl.width * (tpl.cols ?? 1)) / 2}mm;`;
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
        </div>

        <div class="flex flex-col items-center gap-6 p-6 print:block print:p-0">
            <div
                v-for="(labelsOfPage, p) in pages"
                :key="p"
                class="label-page bg-white shadow print:shadow-none"
                :class="current.type === 'sheet' ? 'grid' : ''"
                :style="gridStyle"
            >
                <div
                    v-for="label in labelsOfPage"
                    :key="label.ulid"
                    class="label flex items-center gap-[1.5mm] overflow-hidden p-[1.5mm] text-black"
                    :class="current.type === 'sheet' ? 'outline-dashed outline-1 outline-gray-200 print:outline-none' : ''"
                    :style="labelStyle"
                >
                    <div class="qr aspect-square h-full shrink-0" v-html="label.qr" />
                    <div class="flex min-w-0 flex-1 flex-col justify-center leading-tight" :style="{ fontSize: `${textScale * 7}pt` }">
                        <div v-if="company" class="truncate text-[0.8em] text-gray-600">{{ company }}</div>
                        <div class="truncate font-mono text-[1.35em] font-bold">{{ label.asset_code }}</div>
                        <div class="line-clamp-2 text-[1em]">{{ label.name }}</div>
                        <div v-if="label.customer" class="truncate text-[0.8em] text-gray-600">{{ label.customer }}</div>
                        <div class="mt-auto truncate text-[0.75em] font-semibold">{{ t('labels.scan_hint') }}</div>
                    </div>
                </div>
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
