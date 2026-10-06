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
import { computed } from 'vue';

type Freeze = { from: string; to: string; reason: string };
type Guard = { name: string; phone: string; email: string };

interface RoomForm {
    ulid: string;
    customer_id: number;
    site_id: number | null;
    name: string;
    location: string | null;
    requires_id_number: boolean;
    missing_rules: string;
    accept_mode: string;
    accept_on_enter: boolean;
    entrants_accept_self: boolean;
    guard_link: boolean;
    freeze_periods: Freeze[];
    guard_contacts: Guard[];
    is_active: boolean;
    notes: string | null;
    manager_ids: number[];
    approver_user_id: number | null;
}

const props = defineProps<{
    room: RoomForm | null;
    customers: { id: number; name: string }[];
    sites: { id: number; customer_id: number; name: string }[];
    approvers: { id: number; name: string }[];
    staff: { id: number; name: string }[];
}>();

const title = props.room ? t('server_rooms.edit') : t('server_rooms.create');
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('server_rooms.title'), href: route('room-access.rooms.index') },
    { title, href: props.room ? route('room-access.rooms.edit', props.room.ulid) : route('room-access.rooms.create') },
];

const form = useForm({
    customer_id: props.room?.customer_id ?? (null as number | null),
    site_id: props.room?.site_id ?? (null as number | null),
    name: props.room?.name ?? '',
    location: props.room?.location ?? '',
    requires_id_number: props.room?.requires_id_number ?? false,
    missing_rules: props.room?.missing_rules ?? 'block',
    accept_mode: props.room?.accept_mode ?? 'once_per_version',
    accept_on_enter: props.room?.accept_on_enter ?? false,
    entrants_accept_self: props.room?.entrants_accept_self ?? false,
    guard_link: props.room?.guard_link ?? false,
    freeze_periods: (props.room?.freeze_periods ?? []).map((f) => ({ ...f, reason: f.reason ?? '' })) as Freeze[],
    guard_contacts: (props.room?.guard_contacts ?? []).map((g) => ({ name: g.name, phone: g.phone ?? '', email: g.email ?? '' })) as Guard[],
    is_active: props.room?.is_active ?? true,
    notes: props.room?.notes ?? '',
    manager_ids: props.room?.manager_ids ?? ([] as number[]),
    approver_user_id: props.room?.approver_user_id ?? (null as number | null),
});
const customerSites = computed(() => props.sites.filter((site) => site.customer_id === form.customer_id));

const submit = () => (props.room ? form.put(route('room-access.rooms.update', props.room.ulid)) : form.post(route('room-access.rooms.store')));

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const check = 'size-4 rounded border-input';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="title" />

        <form class="space-y-8 p-4" @submit.prevent="submit">
            <Heading :title="title" />

            <section class="grid gap-4 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="customer" required>{{ t('server_rooms.customer') }}</Label>
                    <select id="customer" v-model="form.customer_id" :class="selectClass" required @change="form.site_id = null">
                        <option :value="null" disabled>—</option>
                        <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                    </select>
                    <InputError :message="form.errors.customer_id" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="site">{{ t('server_rooms.site') }}</Label>
                    <select id="site" v-model="form.site_id" :class="selectClass">
                        <option :value="null">{{ t('server_rooms.no_site') }}</option>
                        <option v-for="site in customerSites" :key="site.id" :value="site.id">{{ site.name }}</option>
                    </select>
                    <InputError :message="form.errors.site_id" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="name" required>{{ t('server_rooms.name') }}</Label>
                    <Input id="name" v-model="form.name" required maxlength="255" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="location">{{ t('server_rooms.location') }}</Label>
                    <Input id="location" v-model="form.location" maxlength="1000" />
                </div>
                <label class="flex items-start gap-2 text-sm sm:col-span-2">
                    <input v-model="form.requires_id_number" type="checkbox" :class="check" class="mt-0.5" />
                    <span
                        >{{ t('server_rooms.requires_id_number')
                        }}<span class="block text-xs text-muted-foreground">{{ t('server_rooms.requires_id_hint') }}</span></span
                    >
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" :class="check" />{{ t('server_rooms.is_active') }}
                </label>
            </section>

            <section class="space-y-3">
                <h3 class="text-sm font-semibold">{{ t('server_rooms.rules_title') }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="missing_rules">{{ t('server_rooms.missing_rules') }}</Label>
                        <select id="missing_rules" v-model="form.missing_rules" :class="selectClass">
                            <option v-for="option in ['block', 'company_terms']" :key="option" :value="option">
                                {{ t(`server_rooms.missing_rules_options.${option}`) }}
                            </option>
                        </select>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="accept_mode">{{ t('server_rooms.accept_mode') }}</Label>
                        <select id="accept_mode" v-model="form.accept_mode" :class="selectClass">
                            <option v-for="option in ['every_request', 'once_per_version']" :key="option" :value="option">
                                {{ t(`server_rooms.accept_mode_options.${option}`) }}
                            </option>
                        </select>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.accept_on_enter" type="checkbox" :class="check" />{{ t('server_rooms.accept_on_enter') }}
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.entrants_accept_self" type="checkbox" :class="check" class="mt-0.5" />
                    <span
                        >{{ t('server_rooms.entrants_accept_self')
                        }}<span class="block text-xs text-muted-foreground">{{ t('server_rooms.entrants_accept_self_off') }}</span></span
                    >
                </label>
            </section>

            <section class="grid gap-4 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="approver">{{ t('server_rooms.approver') }}</Label>
                    <select id="approver" v-model="form.approver_user_id" :class="selectClass">
                        <option :value="null">{{ t('server_rooms.approver_any') }}</option>
                        <option v-for="user in approvers" :key="user.id" :value="user.id">{{ user.name }}</option>
                    </select>
                    <InputError :message="form.errors.approver_user_id" />
                </div>
                <div class="grid content-start gap-2">
                    <span class="text-sm font-medium">{{ t('server_rooms.managers') }}</span>
                    <div class="max-h-40 space-y-1 overflow-y-auto rounded-md border p-2">
                        <label v-for="user in staff" :key="user.id" class="flex items-center gap-2 text-sm">
                            <input v-model="form.manager_ids" type="checkbox" :value="user.id" :class="check" />{{ user.name }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('server_rooms.managers_hint') }}</p>
                </div>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('server_rooms.guards') }}</h3>
                <div v-for="(guard, i) in form.guard_contacts" :key="i" class="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                    <Input v-model="guard.name" :placeholder="t('server_rooms.guard_name')" required maxlength="255" />
                    <Input v-model="guard.phone" :placeholder="t('server_rooms.guard_phone')" maxlength="50" />
                    <Input v-model="guard.email" type="email" :placeholder="t('server_rooms.guard_email')" maxlength="255" />
                    <Button type="button" variant="ghost" size="sm" @click="form.guard_contacts.splice(i, 1)">{{ t('common.delete') }}</Button>
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.guard_link" type="checkbox" :class="check" class="mt-0.5" />
                    <span
                        >{{ t('server_rooms.guard_link')
                        }}<span class="block text-xs text-muted-foreground">{{ t('server_rooms.guard_link_hint') }}</span></span
                    >
                </label>
                <Button type="button" variant="outline" size="sm" @click="form.guard_contacts.push({ name: '', phone: '', email: '' })">{{
                    t('server_rooms.add_guard')
                }}</Button>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('server_rooms.freeze') }}</h3>
                <p v-if="!form.freeze_periods.length" class="text-sm text-muted-foreground">{{ t('server_rooms.no_freeze') }}</p>
                <div v-for="(period, i) in form.freeze_periods" :key="i" class="grid gap-2 sm:grid-cols-[auto_auto_1fr_auto]">
                    <Input v-model="period.from" type="datetime-local" :aria-label="t('server_rooms.freeze_from')" required />
                    <Input v-model="period.to" type="datetime-local" :aria-label="t('server_rooms.freeze_to')" required />
                    <Input v-model="period.reason" :placeholder="t('server_rooms.freeze_reason')" maxlength="255" />
                    <Button type="button" variant="ghost" size="sm" @click="form.freeze_periods.splice(i, 1)">{{ t('common.delete') }}</Button>
                </div>
                <InputError :message="(form.errors as Record<string, string>)['freeze_periods.0.to']" />
                <Button type="button" variant="outline" size="sm" @click="form.freeze_periods.push({ from: '', to: '', reason: '' })">{{
                    t('server_rooms.add_freeze')
                }}</Button>
            </section>

            <div class="grid gap-2">
                <Label for="notes">{{ t('server_rooms.notes') }}</Label>
                <textarea id="notes" v-model="form.notes" rows="3" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm" />
            </div>

            <div class="flex gap-3">
                <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                <Button variant="outline" as-child>
                    <Link :href="room ? route('room-access.rooms.show', room.ulid) : route('room-access.rooms.index')">{{ t('common.cancel') }}</Link>
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
