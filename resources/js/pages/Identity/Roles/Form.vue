<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface RoleForm {
    id: number;
    name: string;
    label: string;
    is_system: boolean;
    permissions: string[];
}

const props = defineProps<{
    role: RoleForm | null;
    permissionGroups: Record<string, string[]>;
}>();

const title = props.role ? t('roles.edit') : t('roles.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('roles.title'), href: route('identity.roles.index') },
    { title, href: props.role ? route('identity.roles.edit', props.role.id) : route('identity.roles.create') },
];

const form = useForm({
    name: props.role?.name ?? '',
    label: props.role?.label ?? '',
    permissions: [...(props.role?.permissions ?? [])],
});

// "asset.view" → "ดู", "asset.view.all" → "เห็นทุกสาขา"
const actionLabel = (permission: string) => t(`permissions.actions.${permission.split('.').slice(1).at(-1)}`);

const submit = () => {
    if (props.role) {
        form.put(route('identity.roles.update', props.role.id));
    } else {
        form.post(route('identity.roles.store'));
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="label">{{ t('roles.label') }}</Label>
                        <Input id="label" v-model="form.label" required />
                        <InputError :message="form.errors.label" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="name">{{ t('roles.name') }}</Label>
                        <Input id="name" v-model="form.name" class="font-mono" :disabled="!!role?.is_system" :required="!role" />
                        <p class="text-xs text-muted-foreground">{{ role?.is_system ? t('roles.system_hint') : t('roles.name_hint') }}</p>
                        <InputError :message="form.errors.name" />
                    </div>
                </div>

                <fieldset class="space-y-4">
                    <legend class="mb-2 text-sm font-medium">{{ t('roles.permissions') }}</legend>
                    <InputError :message="form.errors.permissions" />
                    <div v-for="(permissions, module) in permissionGroups" :key="module" class="rounded-md border p-4">
                        <div class="mb-3 text-sm font-medium">{{ t(`permissions.modules.${module}`) }}</div>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <label v-for="permission in permissions" :key="permission" class="flex items-center gap-2 text-sm">
                                <input v-model="form.permissions" type="checkbox" :value="permission" class="size-4 rounded border-input" />
                                {{ actionLabel(permission) }}
                            </label>
                        </div>
                    </div>
                </fieldset>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('identity.roles.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
