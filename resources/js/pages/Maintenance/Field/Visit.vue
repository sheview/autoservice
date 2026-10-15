<script setup lang="ts">
import BarcodeScanButton from '@/components/BarcodeScanButton.vue';
import InputError from '@/components/InputError.vue';
import type { TableRow } from '@/components/pm/PmGroupedRow.vue';
import PmGroupedTable, { type TablePlace } from '@/components/pm/PmGroupedTable.vue';
import PmResultBadge from '@/components/PmResultBadge.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { assetKeyFromScan } from '@/lib/assetQr';
import { t } from '@/lib/i18n';
import { passRows, type RowItem } from '@/lib/pmRows';
import type { Answer, TemplateField } from '@/lib/pmTemplate';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, LogIn, LogOut, MapPin } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface FieldVisit {
    ulid: string;
    visit_no: string;
    status: string;
    title: string | null;
    customer: string | null;
    due_on: string | null;
    scheduled_on: string | null;
    onsite_at: string | null;
    departed_at: string | null;
    working: boolean;
    can_start: boolean;
}

interface FieldItem {
    id: number;
    result: string;
    has_draft: boolean;
    answers: Record<string, Answer>;
    note: string | null;
    not_inspected_reason: string | null;
    layout: 'per_asset' | 'grouped_table';
    template_code: string | null;
    asset:
        | (Record<string, string | number | null> & {
              id: number;
              ulid: string;
              asset_code: string;
              name: string;
              // fields without a value are left out
              parent_asset_id?: number;
              serial_number?: string;
              property_no?: string;
              location?: string;
              floor?: string;
              room?: string;
          })
        | null;
}

/**
 * A work order on the technician's phone: "arrived" / "left", how far along, and the equipment by
 * floor and room. Equipment of a table template (PC, Network) is filled in right in its table, a row
 * each, a PC with its monitor as a set; other equipment opens its own form (Field/Item). Scanning an
 * asset's QR sticker goes to its row (or its form).
 */
const props = defineProps<{
    visit: FieldVisit;
    items: FieldItem[];
    // the questions of each table template, by template code
    tables: Record<string, TemplateField[]>;
    notInspectedReasons: string[];
}>();

// The rows as saved since the page loaded (rows save on their own, without reloading the page).
const live = ref<FieldItem[]>(props.items.map((item) => ({ ...item })));
watch(
    () => props.items,
    (items) => (live.value = items.map((item) => ({ ...item }))),
);
const merge = (saved: RowItem) => {
    const at = live.value.findIndex((item) => item.id === saved.id);
    if (at >= 0) live.value[at] = { ...live.value[at], ...saved };
};
const inTable = (item: FieldItem) => item.layout === 'grouped_table' && !!item.template_code && !!props.tables[item.template_code] && !!item.asset;

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('my_work.title'), href: route('service.my-work') },
    { title: props.visit.visit_no, href: route('maintenance.field.visit', props.visit.ulid) },
];

const done = computed(() => live.value.filter((i) => i.result !== 'pending').length);
const percent = computed(() => (live.value.length ? Math.round((done.value / live.value.length) * 100) : 0));

const search = ref('');
const matches = (item: FieldItem, term: string) =>
    !term ||
    [item.asset?.asset_code, item.asset?.name, item.asset?.serial_number, item.asset?.property_no].some((v) => v?.toLowerCase().includes(term));
// Floor, then room (else its location as written).
const placeOf = (item: FieldItem) => {
    const a = item.asset;
    const parts = [a?.floor ? t('pm_field.floor', { floor: a.floor }) : null, a?.room || null].filter(Boolean);
    return parts.length ? parts.join(' · ') : a?.location || t('pm_field.no_place');
};

// Equipment with its own form, by place, in the order of the work order.
const groups = computed(() => {
    const term = search.value.trim().toLowerCase();
    const map = new Map<string, { title: string; items: FieldItem[] }>();
    for (const item of live.value.filter((i) => !inTable(i) && matches(i, term))) {
        const title = placeOf(item);
        if (!map.has(title)) map.set(title, { title, items: [] });
        map.get(title)!.items.push(item);
    }
    return [...map.values()];
});

// Each table template's equipment by place; a part (parent_asset_id) right under its main piece when
// both are in the same table. A set is shown when any of its pieces matches the search.
const tableSections = computed(() => {
    const term = search.value.trim().toLowerCase();
    const byCode = new Map<string, FieldItem[]>();
    for (const item of live.value.filter(inTable)) byCode.set(item.template_code!, [...(byCode.get(item.template_code!) ?? []), item]);

    return [...byCode.entries()].map(([code, items]) => {
        const ids = new Set(items.map((item) => item.asset!.id));
        const parts = new Map<number, FieldItem[]>();
        for (const item of items) {
            const parent = item.asset!.parent_asset_id;
            if (parent && ids.has(parent)) parts.set(parent, [...(parts.get(parent) ?? []), item]);
        }
        const places = new Map<string, TablePlace>();
        for (const item of items) {
            const parent = item.asset!.parent_asset_id;
            if (parent && ids.has(parent)) continue;
            const rows = [item, ...(parts.get(item.asset!.id) ?? [])];
            if (!rows.some((row) => matches(row, term))) continue;
            const title = placeOf(item);
            if (!places.has(title)) places.set(title, { title, sets: [] });
            places.get(title)!.sets.push({ rows: rows as TableRow[] });
        }
        return { code, fields: props.tables[code], places: [...places.values()] };
    });
});

// "ผ่านทั้งหมด" / "ผ่านทั้งชุด"
const passing = ref(false);
const passMessage = ref('');
const pass = async (ids: number[]) => {
    passing.value = true;
    passMessage.value = '';
    try {
        const done = await passRows(props.visit.ulid, ids);
        done.items.forEach(merge);
        passMessage.value = done.message;
    } catch {
        passMessage.value = t('pm_field.row_failed');
    } finally {
        passing.value = false;
    }
};

// Scanning a sticker (or typing a code): to the row in the table, or to the equipment's form.
const highlighted = ref<number | null>(null);
const scanMessage = ref('');
const typedCode = ref('');
let unmark: ReturnType<typeof setTimeout> | undefined;
const goTo = async (text: string) => {
    scanMessage.value = '';
    const key = assetKeyFromScan(text);
    const item = key
        ? live.value.find((i) => (key.ulid ? i.asset?.ulid === key.ulid : i.asset?.asset_code.toLowerCase() === key.code?.toLowerCase()))
        : undefined;
    if (!item) {
        scanMessage.value = t('pm_field.scan_not_here', { code: key?.code ?? text });
        return;
    }
    if (!inTable(item)) {
        if (props.visit.working) router.visit(route('maintenance.field.item', [props.visit.ulid, item.id]));
        else scanMessage.value = t('pm_field.not_working');
        return;
    }
    search.value = '';
    highlighted.value = item.id;
    await nextTick();
    document.getElementById(`pm-row-${item.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    clearTimeout(unmark);
    unmark = setTimeout(() => (highlighted.value = null), 4000);
};
const goToTyped = () => {
    if (typedCode.value.trim()) goTo(typedCode.value);
    typedCode.value = '';
};

const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' }) : '');
const busy = ref(false);
const tap = (name: 'arrive' | 'depart') =>
    router.post(
        route(`maintenance.field.${name}`, props.visit.ulid),
        {},
        { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) },
    );
const errors = computed(() => page.props.errors as Record<string, string>);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="visit.visit_no" />

        <div class="mx-auto space-y-4 p-4 pb-24" :class="tableSections.length ? 'max-w-6xl' : 'max-w-3xl'">
            <header class="space-y-1">
                <p class="flex flex-wrap items-center gap-2 font-mono text-sm text-muted-foreground">
                    {{ visit.visit_no }} <PmVisitStatusBadge :status="visit.status" />
                </p>
                <h1 class="text-lg font-semibold">{{ visit.customer ?? visit.title }}</h1>
                <p v-if="visit.title && visit.customer" class="text-sm text-muted-foreground">{{ visit.title }}</p>
            </header>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.visit" />

            <!-- Arrived / left: big, for a thumb -->
            <div class="grid grid-cols-2 gap-3">
                <Button
                    size="lg"
                    class="h-14 text-base"
                    :disabled="busy || !!visit.onsite_at || !(visit.working || visit.can_start)"
                    @click="tap('arrive')"
                >
                    <LogIn class="mr-2 h-5 w-5" />
                    {{ visit.onsite_at ? t('pm_field.arrived_at', { time: time(visit.onsite_at) }) : t('pm_field.arrive') }}
                </Button>
                <Button
                    size="lg"
                    variant="outline"
                    class="h-14 text-base"
                    :disabled="busy || !visit.onsite_at || !visit.working"
                    @click="tap('depart')"
                >
                    <LogOut class="mr-2 h-5 w-5" />
                    {{ visit.departed_at ? t('pm_field.departed_at', { time: time(visit.departed_at) }) : t('pm_field.depart') }}
                </Button>
            </div>
            <p v-if="visit.can_start" class="text-sm text-muted-foreground">{{ t('pm_field.start_hint') }}</p>

            <!-- Progress -->
            <div v-if="items.length" class="space-y-1">
                <div class="flex justify-between text-sm">
                    <span>{{ t('pm_field.progress', { done, total: items.length }) }}</span>
                    <span class="tabular-nums text-muted-foreground">{{ percent }}%</span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${percent}%` }" />
                </div>
                <p v-if="done === items.length" class="text-sm font-medium text-green-700 dark:text-green-300">{{ t('pm_field.all_done') }}</p>
            </div>

            <!-- Find equipment: search, scan its QR sticker, or type its code -->
            <div class="flex flex-wrap gap-2">
                <Input v-if="items.length > 5" v-model="search" type="search" class="h-11 min-w-48 flex-1" :placeholder="t('pm_field.search')" />
                <form class="flex gap-2" @submit.prevent="goToTyped">
                    <Input v-model="typedCode" class="h-11 w-36" :placeholder="t('pm_field.go_to_code')" />
                    <BarcodeScanButton class="h-11" @scanned="goTo" />
                </form>
            </div>
            <p v-if="scanMessage" class="rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                {{ scanMessage }}
            </p>
            <p v-if="passMessage" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ passMessage }}
            </p>

            <!-- Equipment of table templates (PC, Network): filled in right here -->
            <PmGroupedTable
                v-for="section in tableSections"
                :key="section.code"
                :fields="section.fields"
                :places="section.places"
                :visit-ulid="visit.ulid"
                :working="visit.working"
                :reasons="notInspectedReasons"
                :highlighted="highlighted"
                :busy="passing"
                @saved="merge"
                @pass="pass"
            />

            <!-- Equipment by floor / room -->
            <section v-for="group in groups" :key="group.title" class="space-y-2">
                <h2 class="flex items-center gap-1.5 text-sm font-semibold text-muted-foreground">
                    <MapPin class="h-4 w-4" />{{ group.title }}
                    <span class="font-normal">({{ group.items.filter((i) => i.result !== 'pending').length }}/{{ group.items.length }})</span>
                </h2>
                <ul class="space-y-2">
                    <li v-for="item in group.items" :key="item.id">
                        <component
                            :is="visit.working ? Link : 'div'"
                            :href="visit.working ? route('maintenance.field.item', [visit.ulid, item.id]) : undefined"
                            class="flex min-h-14 items-center gap-3 rounded-lg border p-3 text-sm"
                            :class="visit.working ? 'transition hover:bg-muted/50' : 'opacity-80'"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ item.asset?.name ?? '-' }}</span>
                                <span class="block truncate font-mono text-xs text-muted-foreground">
                                    {{ [item.asset?.asset_code, item.asset?.property_no, item.asset?.serial_number].filter(Boolean).join(' · ') }}
                                </span>
                            </span>
                            <span
                                v-if="item.has_draft"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                            >
                                {{ t('pm_field.has_draft') }}
                            </span>
                            <PmResultBadge :result="item.result" />
                            <ChevronRight v-if="visit.working" class="h-4 w-4 shrink-0 text-muted-foreground" />
                        </component>
                    </li>
                </ul>
            </section>

            <Button variant="outline" class="h-12 w-full" as-child>
                <Link :href="route('maintenance.field.sign', visit.ulid)">{{ t('pm_field.submit') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
