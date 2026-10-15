<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface ImportResult {
    ok: boolean;
    total: number;
    created: number;
    updated: number;
    failed: number;
    errors: { row: number | null; messages: string[] }[];
}

defineProps<{
    result: ImportResult | null;
    maxRows: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('contracts.title'), href: route('contract.contracts.index') },
    { title: t('contract_imports.title'), href: route('contract.contracts.import') },
];

const form = useForm<{ file: File | null }>({ file: null });

const submit = () => form.post(route('contract.contracts.import.store'), { forceFormData: true, onSuccess: () => form.reset() });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('contract_imports.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('contract_imports.title')" :description="t('contract_imports.description')" />

            <form class="space-y-4 rounded-md border p-4" @submit.prevent="submit">
                <div class="grid content-start gap-2">
                    <Label for="file">{{ t('contract_imports.file') }}</Label>
                    <input
                        id="file"
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        required
                        class="text-sm"
                        @input="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    />
                    <p class="text-xs text-muted-foreground">{{ t('contract_imports.hint', { max: maxRows }) }}</p>
                    <p class="text-xs text-muted-foreground">{{ t('contract_imports.columns_hint') }}</p>
                    <InputError :message="form.errors.file" />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Button :disabled="form.processing || !form.file">{{ t('contract_imports.upload') }}</Button>
                    <a :href="route('contract.contracts.import.template')" class="text-sm text-primary underline-offset-4 hover:underline">
                        {{ t('contract_imports.template') }}
                    </a>
                </div>
            </form>

            <section v-if="result" class="space-y-2 rounded-md border p-4 text-sm">
                <h3 class="font-semibold">{{ t('contract_imports.result') }}</h3>
                <p v-if="result.ok" :class="result.failed ? '' : 'text-green-700 dark:text-green-400'">
                    {{
                        t('contract_imports.summary', {
                            total: result.total,
                            created: result.created,
                            updated: result.updated,
                            failed: result.failed,
                        })
                    }}
                </p>
                <p v-else class="text-red-700 dark:text-red-400">{{ t('contract_imports.failed') }}</p>
                <ul v-if="result.errors.length" class="space-y-1 text-xs text-red-700 dark:text-red-400">
                    <li v-for="(error, index) in result.errors" :key="index">
                        <span v-if="error.row" class="font-medium">{{ t('contract_imports.row', { row: error.row }) }}: </span>
                        {{ error.messages.join(' · ') }}
                    </li>
                </ul>
            </section>

            <Button variant="outline" as-child>
                <Link :href="route('contract.contracts.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
