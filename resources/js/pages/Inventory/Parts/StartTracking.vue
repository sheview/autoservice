<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SerialInputs from '@/components/SerialInputs.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import type { PartUnitRow } from '@/types/inventory';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Turning serial tracking on for a part: a serial for every piece on hand first. Pieces kept
 * from an earlier time it was tracked are ticked when still really in stock.
 */
const props = defineProps<{
    part: { id: number; code: string; name: string; unit: string; qty_on_hand: number };
    kept: PartUnitRow[];
}>();

const title = t('part_units.start_title', { name: props.part.name });
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('parts.title'), href: route('inventory.parts.index') },
    { title: props.part.code, href: route('inventory.parts.show', props.part.id) },
    { title: t('parts.start_tracking'), href: route('inventory.parts.serials.start', props.part.id) },
];

const form = useForm({
    keep_ids: props.kept.slice(0, props.part.qty_on_hand).map((unit) => unit.id),
    serials: [] as string[],
});
const need = computed(() => Math.max(props.part.qty_on_hand - form.keep_ids.length, 0));
const submit = () => form.post(route('inventory.parts.serials.store-start', props.part.id));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <form class="max-w-4xl space-y-6 p-4" @submit.prevent="submit">
            <Heading :title="title" :description="t('part_units.start_intro', { qty: part.qty_on_hand, unit: part.unit })" />

            <section v-if="kept.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('part_units.start_kept') }}</h3>
                <div class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="unit in kept" :key="unit.id" class="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                        <input v-model="form.keep_ids" type="checkbox" :value="unit.id" class="size-4 rounded border-input" />
                        <span class="font-mono">{{ unit.serial_number }}</span>
                    </label>
                </div>
                <InputError :message="form.errors.keep_ids" />
            </section>

            <section class="space-y-2">
                <h3 v-if="kept.length" class="text-sm font-semibold">{{ t('part_units.start_new') }}</h3>
                <SerialInputs v-model="form.serials" :count="need" />
                <InputError :message="form.errors.serials" />
            </section>

            <div class="flex items-center gap-3">
                <Button :disabled="form.processing">{{ t('part_units.start_submit') }}</Button>
                <Button variant="outline" as-child>
                    <Link :href="route('inventory.parts.show', part.id)">{{ t('common.cancel') }}</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
