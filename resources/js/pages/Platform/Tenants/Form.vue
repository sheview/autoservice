<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface TenantForm {
    ulid: string;
    name: string;
    subdomain: string;
    status: string;
    subscription_starts_on: string | null;
    subscription_ends_on: string | null;
    subscription: NonNullable<SharedData['subscription']>;
}

const props = defineProps<{
    tenant: TenantForm | null;
    statuses: string[];
    warnDays: number;
    graceDays: number;
}>();

const title = props.tenant ? t('tenants.edit') : t('tenants.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('impersonation.title'), href: route('platform.impersonation.index') },
    { title, href: props.tenant ? route('platform.tenants.edit', props.tenant.ulid) : route('platform.tenants.create') },
];

const form = useForm({
    name: props.tenant?.name ?? '',
    subdomain: props.tenant?.subdomain ?? '',
    status: props.tenant?.status ?? 'active',
    subscription_starts_on: props.tenant?.subscription_starts_on ?? '',
    subscription_ends_on: props.tenant?.subscription_ends_on ?? '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
    admin_password_confirmation: '',
});

const submit = () => {
    if (props.tenant) {
        // Admin fields are only for a new company.
        form.transform((data) => ({
            name: data.name,
            subdomain: data.subdomain,
            status: data.status,
            subscription_starts_on: data.subscription_starts_on || null,
            subscription_ends_on: data.subscription_ends_on || null,
        })).put(route('platform.tenants.update', props.tenant.ulid));
    } else {
        form.transform((data) => ({
            ...data,
            subscription_starts_on: data.subscription_starts_on || null,
            subscription_ends_on: data.subscription_ends_on || null,
        })).post(route('platform.tenants.store'));
    }
};

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="max-w-2xl p-4">
            <Heading :title="title" />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="name">{{ t('tenants.name') }}</Label>
                        <Input id="name" v-model="form.name" required autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="subdomain">{{ t('tenants.subdomain') }}</Label>
                        <Input id="subdomain" v-model="form.subdomain" required maxlength="30" class="font-mono lowercase" autocomplete="off" />
                        <p class="text-xs text-muted-foreground">{{ t('tenants.subdomain_hint') }}</p>
                        <InputError :message="form.errors.subdomain" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="status">{{ t('tenants.status') }}</Label>
                        <select id="status" v-model="form.status" :class="selectClass">
                            <option v-for="status in statuses" :key="status" :value="status">{{ t(`impersonation.status.${status}`) }}</option>
                        </select>
                        <InputError :message="form.errors.status" />
                    </div>
                </div>

                <fieldset class="space-y-4 rounded-md border p-4">
                    <legend class="px-1 text-sm font-semibold">{{ t('tenants.subscription') }}</legend>
                    <p v-if="tenant" class="text-sm">
                        {{ t(`subscription.states.${tenant.subscription.state}`) }}
                        <span v-if="tenant.subscription.days_left !== null && tenant.subscription.days_left >= 0" class="text-muted-foreground">
                            · {{ t('subscription.expiring', { days: tenant.subscription.days_left, date: tenant.subscription.ends_on ?? '' }) }}
                        </span>
                    </p>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="starts_on">{{ t('tenants.starts_on') }}</Label>
                            <Input id="starts_on" v-model="form.subscription_starts_on" type="date" />
                            <InputError :message="form.errors.subscription_starts_on" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ends_on">{{ t('tenants.ends_on') }}</Label>
                            <Input id="ends_on" v-model="form.subscription_ends_on" type="date" />
                            <InputError :message="form.errors.subscription_ends_on" />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('tenants.dates_hint', { warn: warnDays, grace: graceDays }) }}</p>
                </fieldset>

                <fieldset v-if="!tenant" class="space-y-4 rounded-md border p-4">
                    <legend class="px-1 text-sm font-semibold">{{ t('tenants.admin') }}</legend>
                    <p class="text-xs text-muted-foreground">{{ t('tenants.admin_hint') }}</p>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="admin_name">{{ t('tenants.admin_name') }}</Label>
                            <Input id="admin_name" v-model="form.admin_name" required autocomplete="off" />
                            <InputError :message="form.errors.admin_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="admin_email">{{ t('tenants.admin_email') }}</Label>
                            <Input id="admin_email" v-model="form.admin_email" type="email" required autocomplete="off" />
                            <InputError :message="form.errors.admin_email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="admin_password">{{ t('tenants.admin_password') }}</Label>
                            <Input id="admin_password" v-model="form.admin_password" type="password" required autocomplete="new-password" />
                            <InputError :message="form.errors.admin_password" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="admin_password_confirmation">{{ t('tenants.admin_password_confirmation') }}</Label>
                            <Input
                                id="admin_password_confirmation"
                                v-model="form.admin_password_confirmation"
                                type="password"
                                required
                                autocomplete="new-password"
                            />
                        </div>
                    </div>
                </fieldset>

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
