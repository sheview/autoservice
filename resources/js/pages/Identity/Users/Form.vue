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
import { computed, watch } from 'vue';

interface UserForm {
    id: number;
    name: string;
    email: string;
    branch_id: number | null;
    customer_id: number | null;
    employee_code: string | null;
    position: string | null;
    phone: string | null;
    service_lines: string[] | null;
    is_active: boolean;
    role: string | null;
}

const props = defineProps<{
    user: UserForm | null;
    branches: { id: number; name: string }[];
    roles: { name: string; label: string }[];
    customers: { id: number; code: string; name: string }[];
    customerRole: string;
    serviceLines: string[];
}>();

const title = props.user ? t('users.edit') : t('users.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('users.title'), href: route('identity.users.index') },
    { title, href: props.user ? route('identity.users.edit', props.user.id) : route('identity.users.create') },
];

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    password_confirmation: '',
    branch_id: props.user?.branch_id ?? null,
    customer_id: props.user?.customer_id ?? null,
    employee_code: props.user?.employee_code ?? '',
    position: props.user?.position ?? '',
    phone: props.user?.phone ?? '',
    service_lines: props.user?.service_lines ?? [],
    is_active: props.user?.is_active ?? true,
    role: props.user?.role ?? props.roles[0]?.name ?? '',
});

// A customer account belongs to a customer and has the customer role only.
const isCustomerAccount = computed(() => form.role === props.customerRole);
watch(isCustomerAccount, (yes) => {
    if (yes) form.branch_id = null;
    else form.customer_id = null;
});

const submit = () => {
    if (props.user) {
        form.put(route('identity.users.update', props.user.id));
    } else {
        form.post(route('identity.users.store'));
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
                        <Label for="name">{{ t('users.name') }}</Label>
                        <Input id="name" v-model="form.name" required autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="email">{{ t('users.email') }}</Label>
                        <Input id="email" v-model="form.email" type="email" required autocomplete="off" />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">{{ t('users.password') }}</Label>
                        <Input id="password" v-model="form.password" type="password" :required="!user" autocomplete="new-password" />
                        <p v-if="user" class="text-xs text-muted-foreground">{{ t('users.password_hint_edit') }}</p>
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">{{ t('users.password_confirmation') }}</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            :required="!user"
                            autocomplete="new-password"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">{{ t('users.role') }}</Label>
                        <select id="role" v-model="form.role" :class="selectClass" required>
                            <option v-for="role in roles" :key="role.name" :value="role.name">{{ role.label }}</option>
                        </select>
                        <InputError :message="form.errors.role" />
                    </div>

                    <div v-if="isCustomerAccount" class="grid gap-2">
                        <Label for="customer_id">{{ t('users.customer') }}</Label>
                        <select id="customer_id" v-model="form.customer_id" :class="selectClass" required>
                            <option :value="null" disabled>{{ t('users.choose_customer') }}</option>
                            <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                                {{ customer.name }} ({{ customer.code }})
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">{{ t('users.customer_hint') }}</p>
                        <InputError :message="form.errors.customer_id" />
                    </div>

                    <div v-else class="grid gap-2">
                        <Label for="branch_id">{{ t('users.branch') }}</Label>
                        <select id="branch_id" v-model="form.branch_id" :class="selectClass">
                            <option :value="null">{{ t('users.no_branch') }}</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                        <InputError :message="form.errors.branch_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="employee_code">{{ t('users.employee_code') }}</Label>
                        <Input id="employee_code" v-model="form.employee_code" />
                        <InputError :message="form.errors.employee_code" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="position">{{ t('users.position') }}</Label>
                        <Input id="position" v-model="form.position" />
                        <InputError :message="form.errors.position" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="phone">{{ t('users.phone') }}</Label>
                        <Input id="phone" v-model="form.phone" type="tel" />
                        <InputError :message="form.errors.phone" />
                    </div>
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">{{ t('users.service_lines') }}</legend>
                    <div class="flex flex-wrap gap-4">
                        <label v-for="line in serviceLines" :key="line" class="flex items-center gap-2 text-sm">
                            <input v-model="form.service_lines" type="checkbox" :value="line" class="size-4 rounded border-input" />
                            {{ t(`service_lines.${line}`) }}
                        </label>
                    </div>
                    <InputError :message="form.errors.service_lines" />
                </fieldset>

                <div class="grid gap-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input" />
                        {{ t('users.is_active') }}
                    </label>
                    <InputError :message="form.errors.is_active" />
                </div>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                    <Button variant="outline" as-child>
                        <Link :href="route('identity.users.index')">{{ t('common.cancel') }}</Link>
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
