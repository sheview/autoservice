<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PmVisitItemCard, { type PmInstrumentOption, type PmItem } from '@/components/PmVisitItemCard.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, List } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * One piece of equipment of a work order, full screen on the technician's phone: its template's
 * fields with big buttons, "ปกติทั้งหมด", the measuring instrument, photos and notes, autosaved as
 * it is typed (PmVisitItemCard + usePmItemForm), then "save and go to the next one".
 */
const props = defineProps<{
    visit: { ulid: string; visit_no: string; status: string; title: string | null; working: boolean };
    item: PmItem;
    position: { index: number; total: number };
    previous: number | null;
    next: number | null;
    instruments: PmInstrumentOption[];
    notInspectedReasons: string[];
    priorities: string[];
    canOpenTicket: boolean;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('my_work.title'), href: route('service.my-work') },
    { title: props.visit.visit_no, href: route('maintenance.field.visit', props.visit.ulid) },
    { title: props.item.asset?.asset_code ?? String(props.item.id), href: route('maintenance.field.item', [props.visit.ulid, props.item.id]) },
];

const itemUrl = (id: number) => route('maintenance.field.item', [props.visit.ulid, id]);
// The last one goes back to the list when saved.
const nextUrl = computed(() => (props.next ? itemUrl(props.next) : route('maintenance.field.visit', props.visit.ulid)));
const errors = computed(() => page.props.errors as Record<string, string>);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`${visit.visit_no} · ${item.asset?.asset_code ?? ''}`" />

        <div class="mx-auto max-w-3xl space-y-3 p-2 pb-24 sm:p-4">
            <nav class="flex items-center justify-between gap-2 text-sm">
                <Button variant="ghost" size="sm" as-child>
                    <Link :href="route('maintenance.field.visit', visit.ulid)"><List class="mr-1 h-4 w-4" />{{ t('pm_field.back') }}</Link>
                </Button>
                <span class="text-muted-foreground">{{ t('pm_field.equipment', { index: position.index, total: position.total }) }}</span>
                <span class="flex gap-1">
                    <Button v-if="previous" variant="ghost" size="icon" :aria-label="t('pm_field.previous')" as-child>
                        <Link :href="itemUrl(previous)"><ChevronLeft class="h-5 w-5" /></Link>
                    </Button>
                    <Button v-if="next" variant="ghost" size="icon" :aria-label="t('pm_field.next')" as-child>
                        <Link :href="itemUrl(next)"><ChevronRight class="h-5 w-5" /></Link>
                    </Button>
                </span>
            </nav>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.visit" />
            <p v-if="!visit.working" class="rounded-md bg-muted px-4 py-2 text-sm text-muted-foreground">{{ t('pm_field.not_working') }}</p>

            <PmVisitItemCard
                :key="item.id"
                :item="item"
                :visit-ulid="visit.ulid"
                :editable="visit.working"
                :can-open-ticket="canOpenTicket"
                :priorities="priorities"
                :instruments="instruments"
                :not-inspected-reasons="notInspectedReasons"
                :next-url="nextUrl"
            />
        </div>
    </AppLayout>
</template>
