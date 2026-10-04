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
    user_ids: number[];
    branch_ids: number[];
    status: 'pending' | 'active' | 'revoked';
    reason: string | null;
    expires_on: string | null;
    granted_by_name: string | null;
    accepted_by_name: string | null;
    revoked_by_name: string | null;
}

interface Viewer {
    ulid: string;
    name: string;
    share: Share | null;
    roles: { name: string; label: string }[];
    people: { id: number; name: string }[];
}

/**
 * One company's data (the owner): which other companies see it, who in them, and the assets
 * of which of the owner's branches. One card per other company; not shared = not seen.
 */
const props = defineProps<{
    tenant: { ulid: string; name: string };
    branches: { id: number; name: string }[];
    abilities: string[];
    companies: Viewer[];
}>();

const page = usePage<SharedData>();
const title = t('shares.title', { tenant: props.tenant.name });
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('impersonation.title'), href: route('platform.impersonation.index') },
    { title, href: route('platform.tenants.shares.edit', props.tenant.ulid) },
];

const editing = ref<string | null>(null);
const form = useForm({
    abilities: [] as string[],
    roles: [] as string[],
    user_ids: [] as number[],
    branch_ids: [] as number[],
    reason: '',
    expires_on: '',
    activate: false,
});
const open = (viewer: Viewer) => {
    editing.value = viewer.ulid;
    const share = viewer.share?.status === 'revoked' ? null : viewer.share;
    form.defaults({
        abilities: share?.abilities ?? [...props.abilities],
        roles: share?.roles ?? [],
        user_ids: share?.user_ids ?? [],
        branch_ids: share?.branch_ids ?? [],
        reason: share?.reason ?? '',
        expires_on: share?.expires_on ?? '',
        activate: false,
    });
    form.reset();
    form.clearErrors();
};
const save = (viewer: Viewer) =>
    form.put(route('platform.tenants.shares.update', [props.tenant.ulid, viewer.ulid]), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
const revoke = (share: Share) =>
    confirm(t('shares.confirm_revoke')) &&
    router.post(route('platform.tenants.shares.revoke', [props.tenant.ulid, share.id]), {}, { preserveScroll: true });

const who = (viewer: Viewer, share: Share) =>
    [
        ...share.roles.map((name) => viewer.roles.find((r) => r.name === name)?.label ?? name),
        ...(share.user_ids.length ? [t('shares.people_count', { count: share.user_ids.length })] : []),
    ].join(', ');
const branchNames = (share: Share) =>
    share.branch_ids.length
        ? t('shares.branches_count', { names: share.branch_ids.map((id) => props.branches.find((b) => b.id === id)?.name ?? id).join(', ') })
        : t('shares.all_branches');

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

            <section v-for="viewer in companies" :key="viewer.ulid" class="space-y-3 rounded-lg border p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="font-semibold">{{ viewer.name }}</div>
                        <template v-if="viewer.share">
                            <span class="inline-block rounded-full px-2 py-0.5 text-xs" :class="statusClass[viewer.share.status]">
                                {{ t(`shares.statuses.${viewer.share.status}`) }}
                            </span>
                            <div class="flex flex-wrap gap-1.5 text-xs">
                                <span
                                    v-for="ability in abilities"
                                    :key="ability"
                                    class="rounded-full border px-2 py-0.5"
                                    :class="
                                        viewer.share.status !== 'revoked' && viewer.share.abilities.includes(ability)
                                            ? 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200'
                                            : 'text-muted-foreground line-through'
                                    "
                                    >{{ viewer.share.abilities.includes(ability) ? '✓' : '✗' }} {{ t(`shares.abilities.${ability}`) }}</span
                                >
                            </div>
                            <div class="text-sm">{{ t('shares.who') }}: {{ who(viewer, viewer.share) }}</div>
                            <div class="text-sm text-muted-foreground">{{ branchNames(viewer.share) }}</div>
                            <div class="text-xs text-muted-foreground">
                                <span v-if="viewer.share.granted_by_name">{{ t('shares.granted_by', { name: viewer.share.granted_by_name }) }}</span>
                                <span v-if="viewer.share.accepted_by_name">
                                    · {{ t('shares.accepted_by', { name: viewer.share.accepted_by_name }) }}</span
                                >
                                <span v-if="viewer.share.revoked_by_name">
                                    · {{ t('shares.revoked_by', { name: viewer.share.revoked_by_name }) }}</span
                                >
                                <span v-if="viewer.share.expires_on"> · {{ t('shares.expires', { date: viewer.share.expires_on }) }}</span>
                            </div>
                        </template>
                        <div v-else class="text-sm text-muted-foreground">{{ t('shares.not_shared') }}</div>
                    </div>
                    <div v-if="editing !== viewer.ulid" class="flex gap-2">
                        <Button size="sm" variant="outline" @click="open(viewer)">{{ t('shares.link') }}</Button>
                        <Button
                            v-if="viewer.share && viewer.share.status !== 'revoked'"
                            size="sm"
                            variant="ghost"
                            class="text-red-600"
                            @click="revoke(viewer.share)"
                        >
                            {{ t('shares.revoke') }}
                        </Button>
                    </div>
                </div>

                <form v-if="editing === viewer.ulid" class="grid gap-5 border-t pt-4 md:grid-cols-2" @submit.prevent="save(viewer)">
                    <fieldset class="space-y-2">
                        <legend class="text-sm font-semibold">{{ t('shares.abilities_label') }}</legend>
                        <label v-for="ability in abilities" :key="ability" class="flex items-center gap-2 text-sm">
                            <input v-model="form.abilities" type="checkbox" :value="ability" class="size-4" />
                            {{ t(`shares.abilities.${ability}`) }}
                        </label>
                        <InputError :message="form.errors.abilities" />
                    </fieldset>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-semibold">{{ t('shares.branches_label') }}</legend>
                        <p class="text-xs text-muted-foreground">{{ t('shares.all_branches_hint') }}</p>
                        <label v-for="branch in branches" :key="branch.id" class="flex items-center gap-2 text-sm">
                            <input v-model="form.branch_ids" type="checkbox" :value="branch.id" class="size-4" />
                            {{ branch.name }}
                        </label>
                    </fieldset>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-semibold">{{ t('shares.roles_label') }}</legend>
                        <p class="text-xs text-muted-foreground">{{ t('shares.who_hint') }}</p>
                        <label v-for="role in viewer.roles" :key="role.name" class="flex items-center gap-2 text-sm">
                            <input v-model="form.roles" type="checkbox" :value="role.name" class="size-4" />
                            {{ role.label }}
                        </label>
                        <InputError :message="form.errors.roles" />
                    </fieldset>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-semibold">{{ t('shares.people_label') }}</legend>
                        <div class="max-h-56 space-y-2 overflow-y-auto rounded-md border p-2">
                            <label v-for="person in viewer.people" :key="person.id" class="flex items-center gap-2 text-sm">
                                <input v-model="form.user_ids" type="checkbox" :value="person.id" class="size-4" />
                                {{ person.name }}
                            </label>
                        </div>
                        <InputError :message="form.errors.user_ids" />
                    </fieldset>

                    <div class="grid gap-1.5">
                        <Label :for="`reason-${viewer.ulid}`">{{ t('shares.reason') }}</Label>
                        <Input :id="`reason-${viewer.ulid}`" v-model="form.reason" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`expires-${viewer.ulid}`">{{ t('shares.expires_on') }}</Label>
                        <Input :id="`expires-${viewer.ulid}`" v-model="form.expires_on" type="date" />
                        <InputError :message="form.errors.expires_on" />
                    </div>
                    <label class="flex items-center gap-2 text-sm md:col-span-2">
                        <input v-model="form.activate" type="checkbox" class="size-4" />
                        {{ t('shares.activate') }}
                    </label>
                    <div class="flex gap-2 md:col-span-2">
                        <Button :disabled="form.processing">{{ t('shares.save') }}</Button>
                        <Button type="button" variant="ghost" @click="editing = null">{{ t('common.cancel') }}</Button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
