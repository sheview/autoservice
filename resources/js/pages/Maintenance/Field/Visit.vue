<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PmResultBadge from '@/components/PmResultBadge.vue';
import PmVisitStatusBadge from '@/components/PmVisitStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, LogIn, LogOut, MapPin } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
    asset: {
        asset_code: string;
        name: string;
        brand: string | null;
        model: string | null;
        serial_number: string | null;
        property_no: string | null;
        location: string | null;
        floor: string | null;
        room: string | null;
    } | null;
}

/**
 * A work order on the technician's phone: "arrived" / "left", how far along, and the equipment by
 * floor and room — each opens its own form (Field/Item).
 */
const props = defineProps<{ visit: FieldVisit; items: FieldItem[] }>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('my_work.title'), href: route('service.my-work') },
    { title: props.visit.visit_no, href: route('maintenance.field.visit', props.visit.ulid) },
];

const done = computed(() => props.items.filter((i) => i.result !== 'pending').length);
const percent = computed(() => (props.items.length ? Math.round((done.value / props.items.length) * 100) : 0));

const search = ref('');
// Equipment by floor, then room (else its location as written), in the order of the work order.
const groups = computed(() => {
    const term = search.value.trim().toLowerCase();
    const shown = props.items.filter(
        (i) =>
            !term || [i.asset?.asset_code, i.asset?.name, i.asset?.serial_number, i.asset?.property_no].some((v) => v?.toLowerCase().includes(term)),
    );
    const map = new Map<string, { title: string; items: FieldItem[] }>();
    for (const item of shown) {
        const a = item.asset;
        const parts = [a?.floor ? t('pm_field.floor', { floor: a.floor }) : null, a?.room || null].filter(Boolean);
        const title = parts.length ? parts.join(' · ') : a?.location || t('pm_field.no_place');
        if (!map.has(title)) map.set(title, { title, items: [] });
        map.get(title)!.items.push(item);
    }
    return [...map.values()];
});

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

        <div class="mx-auto max-w-3xl space-y-4 p-4 pb-24">
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

            <Input v-if="items.length > 5" v-model="search" type="search" class="h-11" :placeholder="t('pm_field.search')" />

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
                <Link :href="route('maintenance.visits.show', visit.ulid)">{{ t('pm_field.submit') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
