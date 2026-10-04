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
 * cell grants the permission in a scope (all / own branch / own records / the account's customer)
 * or not at all, colour-coded. Saved together; the server writes each changed role to the log.
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

// "" = no access; otherwise the scope the role holds the permission in.
const setScope = (role: RoleColumn, permission: string, scope: string) => {
    if (role.locked) return;
    if (scope === '') delete matrix[role.id][permission];
    else matrix[role.id][permission] = scope;
};

// A colour per scope, so the table reads at a glance.
const scopeClass: Record<string, string> = {
    all: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200',
    branch: 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200',
    own: 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200',
    customer: 'bg-violet-100 text-violet-800 dark:bg-violet-900/50 dark:text-violet-200',
    none: 'bg-transparent text-muted-foreground/60 hover:bg-muted',
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

            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <Input v-model="filter" type="search" class="max-w-xs" :placeholder="t('roles.filter_placeholder')" />
                <!-- What each colour means -->
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span
                        v-for="scope in ['all', 'branch', 'own', 'customer']"
                        :key="scope"
                        class="rounded-full px-2.5 py-0.5"
                        :class="scopeClass[scope]"
                    >
                        {{ t(`permissions.scopes.${scope}`) }}
                    </span>
                    <span class="rounded-full px-2.5 py-0.5" :class="scopeClass.none">— {{ t('roles.no_access') }}</span>
                </div>
            </div>
            <p class="text-sm text-muted-foreground">{{ t('roles.scope_hint') }}</p>

            <!-- The matrix: the first column and the header stay in view while scrolling. -->
            <div class="max-h-[70vh] overflow-auto rounded-md border">
                <table class="w-full border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th class="sticky left-0 top-0 z-20 min-w-60 border-b bg-muted px-4 py-3 text-left font-semibold">
                                {{ t('roles.permission') }}
                            </th>
                            <th
                                v-for="role in roles"
                                :key="role.id"
                                class="sticky top-0 z-10 min-w-36 border-b border-l bg-muted px-2 py-3 text-center font-semibold"
                            >
                                <div class="flex items-center justify-center gap-1">
                                    <Lock v-if="role.locked" class="h-3.5 w-3.5" :aria-label="t('roles.locked')" />
                                    {{ role.label ?? role.name }}
                                    <Link
                                        :href="route('identity.roles.edit', role.id)"
                                        class="text-muted-foreground hover:text-foreground"
                                        :aria-label="t('roles.edit_label')"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                    </Link>
                                </div>
                                <div class="text-sm font-normal text-muted-foreground">
                                    {{ t('roles.users_count', { count: role.users_count }) }}
                                    <template v-if="role.external"> · {{ t('roles.external') }}</template>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="resource in visible" :key="resource.key">
                            <tr>
                                <td
                                    :colspan="roles.length + 1"
                                    class="sticky left-0 border-b border-t-2 border-t-border bg-muted/60 px-4 py-2 font-semibold"
                                >
                                    {{ t(`permissions.resources.${resource.key}`) }}
                                </td>
                            </tr>
                            <tr v-for="action in resource.actions" :key="`${resource.key}.${action}`" class="group">
                                <td
                                    class="sticky left-0 border-b bg-background px-4 py-2 pl-8 group-hover:bg-muted"
                                    :title="`${resource.key}.${action}`"
                                >
                                    {{ label(`${resource.key}.${action}`) }}
                                </td>
                                <td v-for="role in roles" :key="role.id" class="border-b border-l px-2 py-1.5 text-center group-hover:bg-muted/50">
                                    <!-- One control per cell: no access, or the scope the role has it in. -->
                                    <span
                                        v-if="role.locked"
                                        class="inline-block w-full rounded-full px-2 py-1 text-sm opacity-70"
                                        :class="scopeClass.all"
                                        :title="t('roles.locked')"
                                    >
                                        {{ t('permissions.scopes.all') }}
                                    </span>
                                    <select
                                        v-else
                                        :value="matrix[role.id][`${resource.key}.${action}`] ?? ''"
                                        class="w-full cursor-pointer appearance-none rounded-full border-0 px-3 py-1 text-center text-sm font-medium"
                                        :class="scopeClass[matrix[role.id][`${resource.key}.${action}`] ?? 'none']"
                                        :aria-label="`${role.label ?? role.name}: ${t(`permissions.resources.${resource.key}`)} ${label(`${resource.key}.${action}`)}`"
                                        @change="setScope(role, `${resource.key}.${action}`, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="">— {{ t('roles.no_access') }}</option>
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
