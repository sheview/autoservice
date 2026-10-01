<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps<{
    year: number;
    holidays: { id: number; date: string; name: string }[];
    can: { create: boolean; delete: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('holidays.title'), href: route('service.holidays.index') }];

const goToYear = (year: number) => router.get(route('service.holidays.index'), { year }, { preserveScroll: true });

const form = useForm({ date: '', name: '' });
const add = () => form.post(route('service.holidays.store'), { preserveScroll: true, onSuccess: () => form.reset() });

const destroy = (holiday: { id: number; name: string }) => {
    if (confirm(t('common.confirm_delete', { name: holiday.name }))) {
        router.delete(route('service.holidays.destroy', holiday.id), { preserveScroll: true });
    }
};

// e.g. "Mon 13 Apr 2026" (internal UI: Gregorian year)
const longDate = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('holidays.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('holidays.title')" :description="t('holidays.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="flex items-center gap-2">
                <Button size="sm" variant="outline" @click="goToYear(props.year - 1)">‹</Button>
                <span class="min-w-24 text-center text-sm font-medium">{{ t('holidays.year') }} {{ year }}</span>
                <Button size="sm" variant="outline" @click="goToYear(props.year + 1)">›</Button>
            </div>

            <form v-if="can.create" class="flex flex-wrap items-start gap-2" @submit.prevent="add">
                <div>
                    <Input v-model="form.date" type="date" required :aria-label="t('holidays.date')" />
                    <InputError :message="form.errors.date" />
                </div>
                <div class="min-w-60 flex-1">
                    <Input v-model="form.name" required :placeholder="t('holidays.name')" :aria-label="t('holidays.name')" />
                    <InputError :message="form.errors.name" />
                </div>
                <Button :disabled="form.processing">{{ t('holidays.add') }}</Button>
            </form>

            <ul v-if="holidays.length" class="divide-y rounded-md border text-sm">
                <li v-for="holiday in holidays" :key="holiday.id" class="flex items-center justify-between gap-2 px-4 py-2">
                    <span>
                        <span class="inline-block w-36 text-muted-foreground">{{ longDate(holiday.date) }}</span>
                        {{ holiday.name }}
                    </span>
                    <button
                        v-if="can.delete"
                        type="button"
                        class="text-xs text-red-700 underline-offset-4 hover:underline dark:text-red-400"
                        @click="destroy(holiday)"
                    >
                        {{ t('common.delete') }}
                    </button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">{{ t('holidays.none') }}</p>
        </div>
    </AppLayout>
</template>
