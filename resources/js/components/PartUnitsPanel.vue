<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import PartUnitTimeline from '@/components/PartUnitTimeline.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';
import type { PartUnitRow } from '@/types/inventory';
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

/**
 * The pieces of a part on its page: filtered by status and serial (server side), each with its
 * history, and corrected (serial / warranty, with the reason) by whoever may change stock.
 */
const props = defineProps<{
    partId: number;
    units: Paginated<PartUnitRow>;
    filters: { status: string | null; q: string | null };
    canCorrect: boolean;
}>();

const status = ref(props.filters.status ?? '');
const q = ref(props.filters.q ?? '');
let timer: ReturnType<typeof setTimeout> | undefined;
const reload = () =>
    router.get(
        route('inventory.parts.show', props.partId),
        { unit_status: status.value || undefined, unit_q: q.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true, only: ['units', 'unitFilters'] },
    );
watch(status, reload);
watch(q, () => {
    clearTimeout(timer);
    timer = setTimeout(reload, 300);
});

const open = ref<number | null>(null);
const editing = ref<number | null>(null);
const form = useForm({ serial_number: '', warranty_until: '', reason: '' });
const edit = (unit: PartUnitRow) => {
    editing.value = unit.id;
    open.value = null;
    form.serial_number = unit.serial_number;
    form.warranty_until = unit.warranty_until ?? '';
    form.reason = '';
    form.clearErrors();
};
const save = () =>
    form.put(route('inventory.part-units.update', editing.value!), { preserveScroll: true, onSuccess: () => (editing.value = null) });

const statusClass: Record<string, string> = {
    in_stock: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    issued: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    removed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-sm font-semibold">{{ t('parts.serials_title') }}</h3>
            <div class="flex flex-wrap gap-2">
                <input v-model="q" type="search" :placeholder="t('part_units.search')" :class="[selectClass, 'w-56 font-mono']" />
                <select v-model="status" :class="selectClass">
                    <option value="">{{ t('part_units.all_statuses') }}</option>
                    <option v-for="s in ['in_stock', 'issued', 'removed']" :key="s" :value="s">{{ t(`part_units.statuses.${s}`) }}</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ t('part_units.serial') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('part_units.status') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('part_units.received_on') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('part_units.source') }}</th>
                        <th class="px-4 py-2 font-medium">{{ t('part_units.warranty_until') }}</th>
                        <th class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <template v-for="unit in units.data" :key="unit.id">
                        <tr class="border-t align-top">
                            <td class="px-4 py-2 font-mono">{{ unit.serial_number }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded px-1.5 py-0.5 text-xs" :class="statusClass[unit.status]">{{ t(`part_units.statuses.${unit.status}`) }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">{{ unit.received_on }}</td>
                            <td class="px-4 py-2">
                                {{ t(`part_units.sources.${unit.source}`) }}
                                <div v-if="unit.supplier" class="text-xs text-muted-foreground">{{ unit.supplier }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">{{ unit.warranty_until ?? t('common.none') }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <Button variant="ghost" size="sm" @click="open = open === unit.id ? null : unit.id">{{ t('part_units.history') }}</Button>
                                <Button v-if="canCorrect" variant="ghost" size="sm" @click="edit(unit)">{{ t('part_units.correct') }}</Button>
                            </td>
                        </tr>
                        <tr v-if="open === unit.id" class="bg-muted/30">
                            <td colspan="6" class="px-6 py-3"><PartUnitTimeline :unit-id="unit.id" /></td>
                        </tr>
                        <tr v-if="editing === unit.id" class="bg-muted/30">
                            <td colspan="6" class="px-6 py-3">
                                <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="save">
                                    <div class="grid gap-1">
                                        <label class="text-xs font-medium">{{ t('part_units.serial') }}</label>
                                        <Input v-model="form.serial_number" class="font-mono" maxlength="100" required />
                                        <InputError :message="form.errors.serial_number" />
                                    </div>
                                    <div class="grid gap-1">
                                        <label class="text-xs font-medium">{{ t('part_units.warranty_until') }}</label>
                                        <Input v-model="form.warranty_until" type="date" />
                                    </div>
                                    <div class="grid gap-1">
                                        <label class="text-xs font-medium">{{ t('part_units.reason') }} <span class="text-red-600">*</span></label>
                                        <Input v-model="form.reason" maxlength="1000" required />
                                        <InputError :message="form.errors.reason" />
                                    </div>
                                    <div class="flex gap-2 sm:col-span-3">
                                        <Button size="sm" :disabled="form.processing">{{ t('part_units.save') }}</Button>
                                        <Button size="sm" type="button" variant="ghost" @click="editing = null">{{ t('common.cancel') }}</Button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="units.data.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ t('part_units.none') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :paginator="units" />
    </section>
</template>
