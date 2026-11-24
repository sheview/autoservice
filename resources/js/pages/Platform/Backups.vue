<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime, formatBytes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Download, Lock, Play } from 'lucide-vue-next';

interface Settings {
    enabled: boolean;
    frequency: string;
    time: string;
    weekday: number;
    monthday: number;
    keep_days: number;
    keep_weeks: number;
    keep_months: number;
    max_storage_mb: number;
    alert_after_days: number;
}

const props = defineProps<{
    settings: Settings;
    status: { last_success_at: string | null; last_failure_at: string | null; last_error: string | null };
    backups: { file: string; date: string; size: number }[];
    stale: boolean;
    encrypted: boolean;
    path: string;
    freeBytes: number | null;
    frequencies: string[];
    limits: Record<string, [number, number]>;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('backups.title'), href: route('platform.backups.index') }];

const form = useForm({ ...props.settings });
const submit = () => form.put(route('platform.backups.update'), { preserveScroll: true });

const running = useForm({});
const runNow = () => {
    if (confirm(t('backups.run_confirm'))) {
        running.post(route('platform.backups.run'), { preserveScroll: true });
    }
};
const refresh = () => router.reload({ only: ['backups', 'status', 'stale', 'freeBytes'] });

const numberFields = ['keep_days', 'keep_weeks', 'keep_months', 'max_storage_mb', 'alert_after_days'] as const;
const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('backups.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('backups.title')" :description="t('backups.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <!-- What can go wrong, first -->
            <div class="space-y-2">
                <p v-if="settings.enabled && stale" class="flex gap-2 rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />{{ t('backups.stale') }}
                </p>
                <p v-if="!encrypted" class="flex gap-2 rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />{{ t('backups.not_encrypted') }}
                </p>
                <p class="flex gap-2 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />{{ t('backups.same_server') }}
                </p>
            </div>

            <!-- Status -->
            <section class="space-y-3 rounded-md border p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">{{ t('backups.status') }}</h3>
                    <Button size="sm" :disabled="running.processing" @click="runNow"><Play class="mr-1 h-4 w-4" />{{ t('backups.run_now') }}</Button>
                </div>
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">{{ t('backups.last_success') }}</dt>
                        <dd>{{ status.last_success_at ? dateTime(status.last_success_at) : t('backups.never') }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">{{ t('backups.last_failure') }}</dt>
                        <dd>
                            {{ status.last_failure_at ? dateTime(status.last_failure_at) : '—' }}
                            <span v-if="status.last_failure_at && status.last_error" class="block break-words text-xs text-red-600">{{ status.last_error }}</span>
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-muted-foreground">{{ t('backups.location') }}</dt>
                        <dd class="break-all font-mono text-xs">{{ path }}</dd>
                        <dd v-if="freeBytes !== null" class="text-xs text-muted-foreground">{{ t('backups.free', { size: formatBytes(freeBytes) }) }}</dd>
                    </div>
                    <div v-if="encrypted" class="flex gap-1 text-xs text-muted-foreground">
                        <Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ t('backups.encrypted') }}
                    </div>
                </dl>
            </section>

            <!-- Settings -->
            <form class="space-y-6 rounded-md border p-4" @submit.prevent="submit">
                <div class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('backups.schedule') }}</h3>
                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                        <Checkbox v-model:checked="form.enabled" />
                        {{ t('backups.enabled') }}
                    </label>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid content-start gap-2">
                            <Label for="frequency">{{ t('backups.frequency') }}</Label>
                            <select id="frequency" v-model="form.frequency" :class="selectClass">
                                <option v-for="f in frequencies" :key="f" :value="f">{{ t(`backups.frequencies.${f}`) }}</option>
                            </select>
                            <InputError :message="form.errors.frequency" />
                        </div>
                        <div v-if="form.frequency === 'weekly'" class="grid content-start gap-2">
                            <Label for="weekday">{{ t('backups.weekday') }}</Label>
                            <select id="weekday" v-model.number="form.weekday" :class="selectClass">
                                <option v-for="d in 7" :key="d" :value="d">{{ t(`backups.weekdays.${d}`) }}</option>
                            </select>
                            <InputError :message="form.errors.weekday" />
                        </div>
                        <div v-if="form.frequency === 'monthly'" class="grid content-start gap-2">
                            <Label for="monthday">{{ t('backups.monthday') }}</Label>
                            <Input id="monthday" v-model.number="form.monthday" type="number" min="1" max="28" required />
                            <p class="text-xs text-muted-foreground">{{ t('backups.monthday_hint') }}</p>
                            <InputError :message="form.errors.monthday" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="time">{{ t('backups.time') }}</Label>
                            <Input id="time" v-model="form.time" type="time" required />
                            <InputError :message="form.errors.time" />
                        </div>
                    </div>
                    <p v-if="form.frequency !== 'daily'" class="text-xs text-amber-600">{{ t('backups.daily_hint') }}</p>
                </div>

                <div class="space-y-4">
                    <h3 class="text-sm font-semibold">{{ t('backups.retention') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div v-for="field in numberFields" :key="field" class="grid content-start gap-2">
                            <Label :for="field">{{ t(`backups.${field}`) }}</Label>
                            <Input :id="field" v-model.number="form[field]" type="number" :min="limits[field][0]" :max="limits[field][1]" required />
                            <p v-if="field === 'max_storage_mb'" class="text-xs text-muted-foreground">{{ t('backups.max_storage_hint') }}</p>
                            <InputError :message="form.errors[field]" />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('backups.alert_hint') }}</p>
                </div>

                <Button :disabled="form.processing">{{ t('common.save') }}</Button>
            </form>

            <!-- Files -->
            <section class="space-y-3 rounded-md border p-4">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">{{ t('backups.files') }}</h3>
                    <Button size="sm" variant="outline" @click="refresh">{{ t('backups.refresh') }}</Button>
                </div>
                <p v-if="backups.length === 0" class="text-sm text-muted-foreground">{{ t('backups.none') }}</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-muted-foreground">
                                <th class="py-2 pr-4 font-medium">{{ t('backups.file_date') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ t('backups.file_size') }}</th>
                                <th class="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="backup in backups" :key="backup.file" class="border-b last:border-0">
                                <td class="py-2 pr-4">
                                    {{ dateTime(backup.date) }}
                                    <span class="block font-mono text-xs text-muted-foreground">{{ backup.file }}</span>
                                </td>
                                <td class="py-2 pr-4 whitespace-nowrap">{{ formatBytes(backup.size) }}</td>
                                <td class="py-2 text-right">
                                    <!-- A file, not a page: a plain link -->
                                    <a :href="route('platform.backups.download', backup.file)" class="inline-flex items-center gap-1 text-violet-600 hover:underline">
                                        <Download class="h-4 w-4" />{{ t('backups.download') }}
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">{{ t('backups.restore_hint') }}</p>
            </section>
        </div>
    </AppLayout>
</template>
