<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Share {
    id: number;
    abilities: string[];
    roles: string[];
    status: 'pending' | 'active' | 'revoked';
    reason: string | null;
    expires_on: string | null;
    granted_by_name: string | null;
    accepted_by_name: string | null;
    revoked_by_name: string | null;
}

interface Company {
    ulid: string;
    name: string;
    share: Share | null;
}

/**
 * What the people of one company may see of each other company: one card per company.
 */
const props = defineProps<{
    tenant: { ulid: string; name: string };
    companies: Company[];
    roles: { name: string; label: string }[];
    abilities: string[];
}>();

const page = usePage<SharedData>();
const title = t('shares.title', { tenant: props.tenant.name });
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('impersonation.title'), href: route('platform.impersonation.index') },
    { title, href: route('platform.tenants.shares.edit', props.tenant.ulid) },
];

// One form per company, opened on demand.
const editing = ref<string | null>(null);
const form = useForm({ abilities: [] as string[], roles: [] as string[], reason: '', expires_on: '', activate: false });
const open = (company: Company) => {
    editing.value = company.ulid;
    const share = company.share?.status === 'revoked' ? null : company.share;
    form.defaults({
        abilities: share?.abilities ?? [...props.abilities],
        roles: share?.roles ?? ['technician', 'central_technician'].filter((r) => props.roles.some((x) => x.name === r)),
        reason: share?.reason ?? '',
        expires_on: share?.expires_on ?? '',
        activate: false,
    });
    form.reset();
    form.clearErrors();
};
const save = (company: Company) =>
    form.put(route('platform.tenants.shares.update', [props.tenant.ulid, company.ulid]), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
const revoke = (share: Share) =>
    confirm(t('shares.confirm_revoke')) &&
    router.post(route('platform.tenants.shares.revoke', [props.tenant.ulid, share.id]), {}, { preserveScroll: true });

const roleLabel = (name: string) => props.roles.find((r) => r.name === name)?.label ?? name;
const statusClass: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    active: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    revoked: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <div class="space-y-6 p-4">
            <Heading :title="title" :description="t('shares.description', { tenant: tenant.name })" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="space-y-3">
                <section v-for="company in companies" :key="company.ulid" class="space-y-3 rounded-lg border p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <div class="font-semibold">{{ company.name }}</div>
                            <template v-if="company.share">
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs" :class="statusClass[company.share.status]">
                                    {{ t(`shares.statuses.${company.share.status}`) }}
                                </span>
                                <div class="text-sm">
                                    {{ company.share.abilities.map((a) => t(`shares.abilities.${a}`)).join(', ') }} ·
                                    {{ company.share.roles.map(roleLabel).join(', ') }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    <span v-if="company.share.granted_by_name">{{
                                        t('shares.granted_by', { name: company.share.granted_by_name })
                                    }}</span>
                                    <span v-if="company.share.accepted_by_name">
                                        · {{ t('shares.accepted_by', { name: company.share.accepted_by_name }) }}</span
                                    >
                                    <span v-if="company.share.revoked_by_name">
                                        · {{ t('shares.revoked_by', { name: company.share.revoked_by_name }) }}</span
                                    >
                                    <span v-if="company.share.expires_on"> · {{ t('shares.expires', { date: company.share.expires_on }) }}</span>
                                </div>
                            </template>
                            <div v-else class="text-sm text-muted-foreground">{{ t('shares.not_shared') }}</div>
                        </div>
                        <div v-if="editing !== company.ulid" class="flex gap-2">
                            <Button size="sm" variant="outline" @click="open(company)">{{ t('shares.link') }}</Button>
                            <Button
                                v-if="company.share && company.share.status !== 'revoked'"
                                size="sm"
                                variant="ghost"
                                class="text-red-600"
                                @click="revoke(company.share)"
                            >
                                {{ t('shares.revoke') }}
                            </Button>
                        </div>
                    </div>

                    <form v-if="editing === company.ulid" class="grid gap-4 border-t pt-4 sm:grid-cols-2" @submit.prevent="save(company)">
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-semibold">{{ t('shares.abilities_label') }}</legend>
                            <label v-for="ability in abilities" :key="ability" class="flex items-center gap-2 text-sm">
                                <input v-model="form.abilities" type="checkbox" :value="ability" class="size-4" />
                                {{ t(`shares.abilities.${ability}`) }}
                            </label>
                            <InputError :message="form.errors.abilities" />
                        </fieldset>
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-semibold">{{ t('shares.roles_label') }}</legend>
                            <label v-for="role in roles" :key="role.name" class="flex items-center gap-2 text-sm">
                                <input v-model="form.roles" type="checkbox" :value="role.name" class="size-4" />
                                {{ role.label }}
                            </label>
                            <InputError :message="form.errors.roles" />
                        </fieldset>
                        <div class="grid gap-1.5">
                            <Label :for="`reason-${company.ulid}`">{{ t('shares.reason') }}</Label>
                            <Input :id="`reason-${company.ulid}`" v-model="form.reason" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label :for="`expires-${company.ulid}`">{{ t('shares.expires_on') }}</Label>
                            <Input :id="`expires-${company.ulid}`" v-model="form.expires_on" type="date" />
                            <InputError :message="form.errors.expires_on" />
                        </div>
                        <label class="flex items-center gap-2 text-sm sm:col-span-2">
                            <input v-model="form.activate" type="checkbox" class="size-4" />
                            {{ t('shares.activate') }}
                        </label>
                        <div class="flex gap-2 sm:col-span-2">
                            <Button :disabled="form.processing">{{ t('shares.save') }}</Button>
                            <Button type="button" variant="ghost" @click="editing = null">{{ t('common.cancel') }}</Button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
