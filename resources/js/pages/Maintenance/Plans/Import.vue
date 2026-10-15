<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { FileSpreadsheet, Upload } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted } from 'vue';

interface ImportRow {
    id: number;
    file_name: string;
    status: 'pending' | 'processing' | 'done' | 'failed';
    total_rows: number;
    created_rows: number;
    updated_rows: number;
    failed_rows: number;
    errors: { row: number | null; messages: string[] }[];
    created_at: string;
}

/**
 * "นำเข้าอุปกรณ์ (Excel)" of a PM plan: one file adds or updates the customer's equipment, puts it
 * into the plan's contract and the plan, with each piece's PM form and the set it belongs to.
 * Runs in the background; the list reloads until it is done.
 */
const props = defineProps<{
    plan: { id: number; title: string; customer: string | null; assets_count: number };
    imports: ImportRow[];
    forms: { code: string; name: string; layout: string }[];
    maxRows: number;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('pm_plans.title'), href: route('maintenance.plans.index') },
    { title: props.plan.title, href: route('maintenance.plans.show', props.plan.id) },
    { title: t('pm_plans.import.title'), href: route('maintenance.plans.import', props.plan.id) },
];

const form = useForm<{ file: File | null }>({ file: null });
const submit = () =>
    form.post(route('maintenance.plans.import.store', props.plan.id), {
        forceFormData: true,
        onSuccess: () => form.reset(),
    });

// While an import runs, reload its result every few seconds.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (props.imports.some((i) => i.status === 'pending' || i.status === 'processing')) {
            router.reload({ only: ['imports', 'plan'] });
        }
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));

const statusClass: Record<string, string> = {
    pending: 'text-muted-foreground',
    processing: 'text-blue-700 dark:text-blue-400',
    done: 'text-green-700 dark:text-green-400',
    failed: 'text-red-700 dark:text-red-400',
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_plans.import.title')" />

        <div class="max-w-4xl space-y-6 p-4">
            <Heading :title="t('pm_plans.import.title')" :description="`${plan.title}${plan.customer ? ` · ${plan.customer}` : ''}`" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <!-- How it works -->
            <ol class="list-decimal space-y-1 rounded-md border bg-muted/30 p-4 pl-8 text-sm">
                <li>{{ t('pm_plans.import.step_template') }}</li>
                <li>{{ t('pm_plans.import.step_fill') }}</li>
                <li>{{ t('pm_plans.import.step_upload', { max: maxRows }) }}</li>
            </ol>

            <form class="space-y-4 rounded-md border p-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="file">{{ t('asset_imports.file') }}</Label>
                    <input
                        id="file"
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        required
                        class="text-sm"
                        @input="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    />
                    <InputError :message="form.errors.file" />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Button :disabled="form.processing || !form.file"><Upload class="mr-1.5 h-4 w-4" />{{ t('pm_plans.import.upload') }}</Button>
                    <a
                        :href="route('maintenance.plans.import.template', plan.id)"
                        class="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-4 hover:underline"
                    >
                        <FileSpreadsheet class="h-4 w-4" />{{ t('pm_plans.import.template') }}
                    </a>
                </div>
            </form>

            <!-- The columns the PM import adds -->
            <section class="space-y-2 text-sm">
                <h3 class="font-semibold">{{ t('pm_plans.import.columns') }}</h3>
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li>{{ t('pm_plans.import.column_form') }}</li>
                    <li>{{ t('pm_plans.import.column_parent') }}</li>
                    <li>{{ t('pm_plans.import.column_place') }}</li>
                    <li>{{ t('pm_plans.import.column_customer') }}</li>
                </ul>
                <details>
                    <summary class="cursor-pointer text-primary">{{ t('pm_plans.import.forms', { count: forms.length }) }}</summary>
                    <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                        <li v-for="item in forms" :key="item.code">
                            <span class="font-mono text-xs">{{ item.code }}</span> {{ item.name }}
                            <span class="text-xs text-muted-foreground">({{ t(`pm_checklists.layouts.${item.layout}`) }})</span>
                        </li>
                    </ul>
                </details>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('asset_imports.history') }}</h3>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('asset_imports.uploaded_at') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('asset_imports.file_name') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('common.status') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('asset_imports.result') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in imports" :key="item.id" class="border-t align-top">
                                <td class="whitespace-nowrap px-4 py-2">{{ dateTime(item.created_at) }}</td>
                                <td class="px-4 py-2">{{ item.file_name }}</td>
                                <td class="whitespace-nowrap px-4 py-2" :class="statusClass[item.status]">
                                    {{ t(`asset_imports.statuses.${item.status}`) }}
                                </td>
                                <td class="px-4 py-2">
                                    <div v-if="item.status === 'done'">
                                        {{
                                            t('asset_imports.summary', {
                                                total: item.total_rows,
                                                created: item.created_rows,
                                                updated: item.updated_rows,
                                                failed: item.failed_rows,
                                            })
                                        }}
                                    </div>
                                    <details v-if="item.errors.length" class="mt-1">
                                        <summary class="cursor-pointer text-red-700 dark:text-red-400">{{ t('asset_imports.show_errors') }}</summary>
                                        <ul class="mt-1 space-y-1 text-xs">
                                            <li v-for="(error, index) in item.errors" :key="index">
                                                <span v-if="error.row" class="font-medium">{{ t('asset_imports.row', { row: error.row }) }}: </span>
                                                {{ error.messages.join(' · ') }}
                                            </li>
                                        </ul>
                                    </details>
                                </td>
                            </tr>
                            <tr v-if="imports.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="flex items-center gap-3">
                <Button variant="outline" as-child>
                    <Link :href="route('maintenance.plans.show', plan.id)">{{ t('pm_plans.import.back') }}</Link>
                </Button>
                <span class="text-sm text-muted-foreground">{{ t('pm_plans.import.in_plan', { count: plan.assets_count }) }}</span>
            </div>
        </div>
    </AppLayout>
</template>
