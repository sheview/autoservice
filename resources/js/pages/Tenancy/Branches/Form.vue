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

const props = defineProps<{
    branch: { id: number; code: string; name: string; address: string | null; province: string | null } | null;
}>();

const title = props.branch ? t('branches.edit') : t('branches.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('branches.title'), href: route('tenancy.branches.index') },
    { title, href: props.branch ? route('tenancy.branches.edit', props.branch.id) : route('tenancy.branches.create') },
];

const form = useForm({
    code: props.branch?.code ?? '',
    name: props.branch?.name ?? '',
    address: props.branch?.address ?? '',
    province: props.branch?.province ?? '',
});

const submit = () => {
    if (props.branch) {
        form.put(route('tenancy.branches.update', props.branch.id), { preserveScroll: true });
    } else {
        form.post(route('tenancy.branches.store'), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="p-4">
            <Heading :title="title" />

            <form class="max-w-2xl space-y-6" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="code" required>{{ t('branches.code') }}</Label>
                        <Input id="code" v-model="form.code" class="font-mono uppercase" maxlength="20" required autocomplete="off" />
                        <p class="text-xs text-muted-foreground">{{ t('branches.code_hint') }}</p>
                        <InputError :message="form.errors.code" />
                    </div>
                    <div class="grid content-start gap-2 sm:col-span-2">
                        <Label for="name" required>{{ t('branches.name') }}</Label>
                        <Input id="name" v-model="form.name" required autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>
                </div>

                <div class="grid content-start gap-2">
                    <Label for="address">{{ t('branches.address') }}</Label>
                    <textarea
                        id="address"
                        v-model="form.address"
                        rows="3"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.address" />
                </div>

                <div class="grid content-start gap-2 sm:max-w-xs">
                    <Label for="province">{{ t('branches.province') }}</Label>
                    <Input id="province" v-model="form.province" autocomplete="off" />
                    <InputError :message="form.errors.province" />
                </div>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('tenancy.branches.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
