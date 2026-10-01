<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Lock, Pencil, Plus } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface RoleColumn {
    id: number;
    name: string;
    label: string | null;
    is_system: boolean;
    users_count: number;
    locked: boolean;
    external: boolean;
}

/**
 * The permissions matrix: a row per permission (grouped by menu), a column per role. Ticking a
 * cell grants the permission; its scope (all / own branch / own records / the account's customer)
 * is chosen under the tick. Saved together; the server writes each changed role to the log.
 */
const props = defineProps<{
    roles: RoleColumn[];
    grants: Record<number, Record<string, string>>;
    resources: { key: string; actions: string[] }[];
    scopes: string[];
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('roles.title'), href: route('identity.roles.index') }];

// Working copy: role id => permission => scope.
const copy = () => Object.fromEntries(props.roles.map((role) => [role.id, { ...(props.grants[role.id] ?? {}) }]));
const matrix = reactive<Record<number, Record<string, string>>>(copy());
const saving = ref(false);
const errors = computed(() => page.props.errors as Record<string, string>);

const dirty = computed(() => JSON.stringify(matrix) !== JSON.stringify(copy()));

const scopesFor = (role: RoleColumn) => (role.external ? ['customer'] : props.scopes.filter((scope) => scope !== 'customer'));
const defaultScope = (role: RoleColumn) => (role.external ? 'customer' : 'all');

const granted = (role: RoleColumn, permission: string) => role.locked || permission in matrix[role.id];
const toggle = (role: RoleColumn, permission: string) => {
    if (role.locked) return;
    if (permission in matrix[role.id]) {
        delete matrix[role.id][permission];
    } else {
        matrix[role.id][permission] = defaultScope(role);
    }
};

const label = (permission: string) => {
    const [resource, action] = permission.split('.');
    const override = `permissions.overrides.${resource}.${action}`;
    const text = t(override);
    return text === override ? t(`permissions.actions.${action}`) : text;
};

// Rows shown: those whose menu or action matches the filter.
const filter = ref('');
const visible = computed(() => {
    const needle = filter.value.trim().toLowerCase();
    if (!needle) return props.resources;
    return props.resources
        .map((resource) => {
            const name = t(`permissions.resources.${resource.key}`).toLowerCase();
            const hit = name.includes(needle) || resource.key.includes(needle);
            return {
                ...resource,
                actions: hit ? resource.actions : resource.actions.filter((a) => label(`${resource.key}.${a}`).toLowerCase().includes(needle)),
            };
        })
        .filter((resource) => resource.actions.length > 0);
});

const save = () => {
    const payload = Object.fromEntries(props.roles.filter((role) => !role.locked).map((role) => [role.id, matrix[role.id]]));
    router.put(
        route('identity.roles.matrix'),
        { matrix: payload },
        { preserveScroll: true, onStart: () => (saving.value = true), onFinish: () => (saving.value = false) },
    );
};
const reset = () => {
    for (const role of props.roles) matrix[role.id] = { ...(props.grants[role.id] ?? {}) };
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('roles.title')" />

        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('roles.title')" :description="t('roles.description')" />
                <Button as-child variant="outline">
                    <Link :href="route('identity.roles.create')">
                        <Plus class="h-4 w-4" />
                        {{ t('roles.create') }}
                    </Link>
                </Button>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.matrix" />

            <div class="flex flex-wrap items-center gap-3">
                <Input v-model="filter" type="search" class="max-w-xs" :placeholder="t('roles.filter_placeholder')" />
                <p class="text-xs text-muted-foreground">{{ t('roles.scope_hint') }}</p>
            </div>

            <!-- The matrix: the first column and the header stay in view while scrolling. -->
            <div class="max-h-[70vh] overflow-auto rounded-md border">
                <table class="w-full border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th class="sticky left-0 top-0 z-20 min-w-56 border-b bg-muted px-3 py-2 text-left font-medium">
                                {{ t('roles.permission') }}
                            </th>
                            <th
                                v-for="role in roles"
                                :key="role.id"
                                class="sticky top-0 z-10 min-w-32 border-b bg-muted px-2 py-2 text-center font-medium"
                            >
                                <div class="flex items-center justify-center gap-1">
                                    <Lock v-if="role.locked" class="h-3 w-3" :aria-label="t('roles.locked')" />
                                    {{ role.label ?? role.name }}
                                    <Link
                                        :href="route('identity.roles.edit', role.id)"
                                        class="text-muted-foreground hover:text-foreground"
                                        :aria-label="t('roles.edit_label')"
                                    >
                                        <Pencil class="h-3 w-3" />
                                    </Link>
                                </div>
                                <div class="text-xs font-normal text-muted-foreground">
                                    {{ t('roles.users_count', { count: role.users_count }) }}
                                    <template v-if="role.external"> · {{ t('roles.external') }}</template>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="resource in visible" :key="resource.key">
                            <tr>
                                <td :colspan="roles.length + 1" class="sticky left-0 border-b bg-muted/40 px-3 py-1.5 text-xs font-semibold">
                                    {{ t(`permissions.resources.${resource.key}`) }}
                                </td>
                            </tr>
                            <tr v-for="action in resource.actions" :key="`${resource.key}.${action}`" class="hover:bg-muted/20">
                                <td class="sticky left-0 border-b bg-background px-3 py-1.5">
                                    {{ label(`${resource.key}.${action}`) }}
                                    <span class="ml-1 font-mono text-[10px] text-muted-foreground">{{ resource.key }}.{{ action }}</span>
                                </td>
                                <td v-for="role in roles" :key="role.id" class="border-b px-2 py-1.5 text-center align-top">
                                    <input
                                        type="checkbox"
                                        class="size-4 rounded border-input"
                                        :checked="granted(role, `${resource.key}.${action}`)"
                                        :disabled="role.locked"
                                        :aria-label="`${role.label ?? role.name}: ${t(`permissions.resources.${resource.key}`)} ${label(`${resource.key}.${action}`)}`"
                                        @change="toggle(role, `${resource.key}.${action}`)"
                                    />
                                    <select
                                        v-if="!role.locked && `${resource.key}.${action}` in matrix[role.id]"
                                        v-model="matrix[role.id][`${resource.key}.${action}`]"
                                        class="mt-1 block w-full rounded border border-input bg-transparent px-1 py-0.5 text-xs"
                                        :aria-label="t('roles.scope_hint')"
                                    >
                                        <option v-for="scope in scopesFor(role)" :key="scope" :value="scope">
                                            {{ t(`permissions.scopes.${scope}`) }}
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="sticky bottom-0 flex flex-wrap items-center gap-3 border-t bg-background py-3">
                <Button :disabled="saving || !dirty" @click="save">{{ t('roles.save') }}</Button>
                <Button variant="ghost" :disabled="!dirty" @click="reset">{{ t('roles.reset') }}</Button>
                <span v-if="dirty" class="text-xs text-amber-700 dark:text-amber-400">{{ t('roles.unsaved') }}</span>
            </div>
        </div>
    </AppLayout>
</template>
