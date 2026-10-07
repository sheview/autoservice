<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ImportResult {
    total: number;
    created: number;
    skipped: number;
    failed: number;
    errors: { row: number | null; messages: string[] }[];
}

defineProps<{
    roles: { name: string; label: string }[];
    branches: { code: string; name: string }[];
    customerRole: string;
    maxRows: number;
    result: ImportResult | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: t('users.title'), href: route('identity.users.index') },
    { title: t('user_import.title'), href: route('identity.users.import') },
];

const fileInput = ref<HTMLInputElement | null>(null);
const form = useForm<{ file: File | null }>({ file: null });

// The file holds passwords: clear it from the form once sent.
const submit = () =>
    form.post(route('identity.users.import.store'), {
        forceFormData: true,
        onFinish: () => {
            form.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });

const roleLabel = (roles: { name: string; label: string }[], name: string) => roles.find((role) => role.name === name)?.label ?? name;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('user_import.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('user_import.title')" :description="t('user_import.description')" />

            <section v-if="result" class="space-y-3 rounded-md border p-4">
                <h3 class="text-sm font-semibold">{{ t('user_import.result') }}</h3>
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-muted-foreground">{{ t('user_import.total') }}</dt>
                        <dd class="text-lg font-semibold">{{ result.total }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">{{ t('user_import.created') }}</dt>
                        <dd class="text-lg font-semibold text-green-700 dark:text-green-400">{{ result.created }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">{{ t('user_import.skipped') }}</dt>
                        <dd class="text-lg font-semibold text-amber-700 dark:text-amber-400">{{ result.skipped }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">{{ t('user_import.failed') }}</dt>
                        <dd class="text-lg font-semibold text-red-700 dark:text-red-400">{{ result.failed }}</dd>
                    </div>
                </dl>
                <ul v-if="result.errors.length" class="space-y-1 text-sm">
                    <li v-for="(error, index) in result.errors" :key="index">
                        <span class="font-medium">{{ error.row ? t('user_import.row', { row: error.row }) : t('user_import.file_error') }}: </span>
                        {{ error.messages.join(' · ') }}
                    </li>
                </ul>
            </section>

            <form class="space-y-4 rounded-md border p-4" @submit.prevent="submit">
                <div class="grid content-start gap-2">
                    <Label for="file">{{ t('user_import.file') }}</Label>
                    <input
                        id="file"
                        ref="fileInput"
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        required
                        class="text-sm"
                        @input="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                    />
                    <InputError :message="form.errors.file" />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Button :disabled="form.processing || !form.file">
                        {{ form.processing ? t('user_import.uploading') : t('user_import.upload') }}
                    </Button>
                    <a :href="route('identity.users.import.template')" class="text-sm text-primary underline-offset-4 hover:underline">
                        {{ t('user_import.template') }}
                    </a>
                </div>
            </form>

            <section class="space-y-2 text-sm">
                <h3 class="font-semibold">{{ t('user_import.rules_title') }}</h3>
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li>{{ t('user_import.rule_required', { max: maxRows }) }}</li>
                    <li>{{ t('user_import.rule_password') }}</li>
                    <li>{{ t('user_import.rule_existing') }}</li>
                    <li>{{ t('user_import.rule_customer', { role: roleLabel(roles, customerRole) }) }}</li>
                    <li>{{ t('user_import.rule_delete_file') }}</li>
                </ul>

                <div class="grid gap-4 pt-2 sm:grid-cols-2">
                    <div>
                        <h4 class="mb-1 font-medium">{{ t('user_import.roles') }}</h4>
                        <ul class="space-y-0.5">
                            <li v-for="role in roles" :key="role.name">
                                {{ role.label }} <span class="font-mono text-xs text-muted-foreground">({{ role.name }})</span>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <h4 class="mb-1 font-medium">{{ t('user_import.branches') }}</h4>
                        <ul v-if="branches.length" class="space-y-0.5">
                            <li v-for="branch in branches" :key="branch.code">
                                <span class="font-mono text-xs">{{ branch.code }}</span> {{ branch.name }}
                            </li>
                        </ul>
                        <p v-else class="text-muted-foreground">{{ t('user_import.no_branches') }}</p>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
