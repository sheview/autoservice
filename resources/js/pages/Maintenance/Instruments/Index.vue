<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from 'lucide-vue-next';
import { ref } from 'vue';

interface Instrument {
    id: number;
    name: string;
    serial_no: string;
    notes: string | null;
    calibration_due_at: string | null;
    calibration: 'ok' | 'soon' | 'overdue' | null;
}

interface Filters {
    search: string;
    due: boolean;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

/** The measuring instruments a technician picks when recording PM, with their calibration due dates. */
const props = defineProps<{ instruments: Paginated<Instrument>; filters: Filters }>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_instruments.title'), href: route('maintenance.instruments.index') }];
const filters = useListFilters('maintenance.instruments.index', props.filters);
const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};

// One form adds a new instrument or changes the one picked.
const editing = ref<Instrument | null>(null);
const form = useForm({ name: '', serial_no: '', calibration_due_at: '' as string | null, notes: '' as string | null });
const edit = (instrument: Instrument | null) => {
    editing.value = instrument;
    form.clearErrors();
    form.defaults({
        name: instrument?.name ?? '',
        serial_no: instrument?.serial_no ?? '',
        calibration_due_at: instrument?.calibration_due_at ?? '',
        notes: instrument?.notes ?? '',
    });
    form.reset();
};
const submit = () => {
    const done = { preserveScroll: true, onSuccess: () => edit(null) };
    if (editing.value) form.put(route('maintenance.instruments.update', editing.value.id), done);
    else form.post(route('maintenance.instruments.store'), done);
};
const destroy = (instrument: Instrument) =>
    confirm(t('pm_instruments.confirm_delete', { name: instrument.name })) &&
    router.delete(route('maintenance.instruments.destroy', instrument.id), { preserveScroll: true });

const calibrationClass: Record<string, string> = {
    ok: 'text-green-700 dark:text-green-300',
    soon: 'text-amber-700 dark:text-amber-300',
    overdue: 'font-semibold text-red-700 dark:text-red-300',
};
const columns = ['name', 'serial_no', 'calibration_due_at'] as const;
const date = (day: string) =>
    new Date(`${day}T00:00:00`).toLocaleDateString('th-TH-u-ca-gregory', { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_instruments.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('pm_instruments.title')" :description="t('pm_instruments.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <form class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_1fr_11rem_1fr_auto] sm:items-end" @submit.prevent="submit">
                <div class="grid gap-1">
                    <Label for="name">{{ t('pm_instruments.name') }}</Label>
                    <Input id="name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1">
                    <Label for="serial_no">{{ t('pm_instruments.serial_no') }}</Label>
                    <Input id="serial_no" v-model="form.serial_no" class="font-mono" required />
                    <InputError :message="form.errors.serial_no" />
                </div>
                <div class="grid gap-1">
                    <Label for="calibration_due_at">{{ t('pm_instruments.calibration_due_at') }}</Label>
                    <Input id="calibration_due_at" v-model="form.calibration_due_at as string" type="date" />
                    <InputError :message="form.errors.calibration_due_at" />
                </div>
                <div class="grid gap-1">
                    <Label for="notes">{{ t('pm_instruments.notes') }}</Label>
                    <Input id="notes" v-model="form.notes as string" />
                </div>
                <div class="flex gap-2">
                    <Button :disabled="form.processing">{{ editing ? t('common.save') : t('pm_instruments.create') }}</Button>
                    <Button v-if="editing" type="button" variant="ghost" @click="edit(null)">{{ t('common.cancel') }}</Button>
                </div>
            </form>

            <div class="flex flex-wrap items-center gap-4">
                <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('pm_instruments.search_placeholder')" />
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="filters.due" type="checkbox" class="size-4 rounded border-input" />
                    {{ t('pm_instruments.due_filter') }}
                </label>
            </div>

            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th v-for="column in columns" :key="column" class="px-4 py-2 font-medium">
                                <button type="button" class="flex items-center gap-1" @click="sortBy(column)">
                                    {{ t(`pm_instruments.${column}`) }}
                                    <template v-if="filters.sort === column">
                                        <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </template>
                                </button>
                            </th>
                            <th class="px-4 py-2 font-medium">{{ t('pm_instruments.notes') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="instrument in instruments.data" :key="instrument.id" class="border-t">
                            <td class="px-4 py-2 font-medium">{{ instrument.name }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ instrument.serial_no }}</td>
                            <td class="whitespace-nowrap px-4 py-2">
                                <template v-if="instrument.calibration_due_at">
                                    {{ date(instrument.calibration_due_at) }}
                                    <span v-if="instrument.calibration" class="ml-1 text-xs" :class="calibrationClass[instrument.calibration]">
                                        ({{ t(`pm_instruments.calibration.${instrument.calibration}`) }})
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground">-</span>
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">{{ instrument.notes }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <Button variant="ghost" size="sm" @click="edit(instrument)">{{ t('common.edit') }}</Button>
                                <Button variant="ghost" size="sm" class="text-destructive" @click="destroy(instrument)">{{
                                    t('common.delete')
                                }}</Button>
                            </td>
                        </tr>
                        <tr v-if="instruments.data.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="instruments" />
        </div>
    </AppLayout>
</template>
