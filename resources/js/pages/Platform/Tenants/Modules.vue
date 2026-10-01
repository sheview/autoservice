<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    tenant: { ulid: string; name: string; subdomain: string };
    modules: Record<string, boolean>;
}>();

const title = t('tenant_modules.title', { tenant: props.tenant.name });
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('impersonation.title'), href: route('platform.impersonation.index') },
    { title, href: route('platform.tenants.modules.edit', props.tenant.ulid) },
];

const form = useForm({ modules: { ...props.modules } });

const submit = () => form.put(route('platform.tenants.modules.update', props.tenant.ulid));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" :description="t('tenant_modules.description')" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="divide-y rounded-md border">
                    <label v-for="(on, key) in form.modules" :key="key" class="flex items-start gap-3 px-4 py-3">
                        <input v-model="form.modules[key]" type="checkbox" class="mt-0.5 size-4 rounded border-input" />
                        <span>
                            <span class="block text-sm font-medium">{{ t(`tenant_modules.modules.${key}.name`) }}</span>
                            <span class="block text-xs text-muted-foreground">{{ t(`tenant_modules.modules.${key}.description`) }}</span>
                        </span>
                    </label>
                </div>
                <p class="text-xs text-muted-foreground">{{ t('tenant_modules.hint') }}</p>
                <InputError :message="form.errors.modules" />

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('platform.impersonation.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
