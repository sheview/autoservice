<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
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
    user: string | null;
    created_at: string;
}

const props = defineProps<{
    imports: Paginated<ImportRow>;
    maxRows: number;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('assets.title'), href: route('asset.assets.index') },
    { title: t('asset_imports.title'), href: route('asset.imports.index') },
];

const form = useForm<{ file: File | null }>({ file: null });

const submit = () => form.post(route('asset.imports.store'), { forceFormData: true, onSuccess: () => form.reset() });

// While an import is still running, reload the list every few seconds.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (props.imports.data.some((i) => i.status === 'pending' || i.status === 'processing')) {
            router.reload({ only: ['imports'] });
        }
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));

const dateTime = (iso: string) => new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Bangkok' });

const statusClass: Record<string, string> = {
    pending: 'text-muted-foreground',
    processing: 'text-blue-700 dark:text-blue-400',
    done: 'text-green-700 dark:text-green-400',
    failed: 'text-red-700 dark:text-red-400',
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('asset_imports.title')" />

        <div class="max-w-5xl space-y-6 p-4">
            <Heading :title="t('asset_imports.title')" :description="t('asset_imports.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

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
                    <p class="text-xs text-muted-foreground">{{ t('asset_imports.hint', { max: maxRows }) }}</p>
                    <InputError :message="form.errors.file" />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Button :disabled="form.processing || !form.file">{{ t('asset_imports.upload') }}</Button>
                    <a :href="route('asset.imports.template')" class="text-sm text-primary underline-offset-4 hover:underline">
                        {{ t('asset_imports.template') }}
                    </a>
                </div>
            </form>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('asset_imports.history') }}</h3>
                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('asset_imports.uploaded_at') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('asset_imports.file_name') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('asset_imports.user') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('common.status') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('asset_imports.result') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in imports.data" :key="item.id" class="border-t align-top">
                                <td class="whitespace-nowrap px-4 py-2">{{ dateTime(item.created_at) }}</td>
                                <td class="px-4 py-2">{{ item.file_name }}</td>
                                <td class="px-4 py-2">{{ item.user ?? t('common.none') }}</td>
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
                            <tr v-if="imports.data.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="imports" />
            </section>

            <Button variant="outline" as-child>
                <Link :href="route('asset.assets.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
