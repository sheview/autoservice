<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Version {
    id: number;
    version: number;
    summary: string[];
    effective_on: string;
    received_from: string | null;
    received_on: string | null;
    note: string | null;
    created_by_name: string | null;
    created_at: string;
    file: string | null;
    in_effect: boolean;
}

const props = defineProps<{
    room: {
        ulid: string;
        name: string;
        location: string | null;
        customer: string;
        site: string | null;
        requires_id_number: boolean;
        missing_rules: string;
        accept_mode: string;
        accept_on_enter: boolean;
        entrants_accept_self: boolean;
        freeze_periods: { from: string; to: string; reason: string | null }[];
        guard_contacts: { name: string; phone: string | null; email: string | null }[];
        is_active: boolean;
        notes: string | null;
        managers: string[];
        approvers: { position: number; side: string; name: string | null }[];
    };
    versions: Version[];
    currentVersion: number | null;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('server_rooms.title'), href: route('room-access.rooms.index') },
    { title: props.room.name, href: route('room-access.rooms.show', props.room.ulid) },
];

const adding = ref(props.versions.length === 0);
const form = useForm({
    summary: [''] as string[],
    effective_on: new Date().toLocaleDateString('sv-SE'),
    received_from: '',
    received_on: '',
    note: '',
    file: null as File | null,
});
const copyCurrent = () => (form.summary = [...(props.versions[0]?.summary ?? [''])]);
const publish = () =>
    form.post(route('room-access.rooms.rules.store', props.room.ulid), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            adding.value = false;
        },
    });

const destroy = () => {
    if (confirm(t('server_rooms.delete_confirm', { name: props.room.name }))) router.delete(route('room-access.rooms.destroy', props.room.ulid));
};
const versionState = (v: Version) =>
    v.version === props.currentVersion ? 'in_effect' : !v.in_effect ? 'upcoming' : 'superseded';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="room.name" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="room.name" :description="[room.customer, room.site, room.location].filter(Boolean).join(' · ')" />
                <div class="flex gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('room-access.rooms.edit', room.ulid)">{{ t('common.edit') }}</Link>
                    </Button>
                    <Button variant="outline" @click="destroy">{{ t('common.delete') }}</Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <dl class="grid gap-x-6 gap-y-3 rounded-md border p-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('server_rooms.requires_id_number') }}</dt>
                    <dd>{{ room.requires_id_number ? t('common.yes') : t('common.no') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('server_rooms.missing_rules') }}</dt>
                    <dd>{{ t(`server_rooms.missing_rules_options.${room.missing_rules}`) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('server_rooms.accept_mode') }}</dt>
                    <dd>
                        {{ t(`server_rooms.accept_mode_options.${room.accept_mode}`) }}
                        <span v-if="room.accept_on_enter" class="block text-xs">{{ t('server_rooms.accept_on_enter') }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            room.entrants_accept_self ? t('server_rooms.entrants_accept_self') : t('server_rooms.entrants_accept_self_off')
                        }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('server_rooms.approval') }}</dt>
                    <dd v-for="step in room.approvers" :key="step.position">
                        {{ step.position }}. {{ step.name ?? t('server_rooms.approver_any') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('server_rooms.managers') }}</dt>
                    <dd>{{ room.managers.length ? room.managers.join(', ') : '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">{{ t('server_rooms.guards') }}</dt>
                    <dd v-if="!room.guard_contacts.length">-</dd>
                    <dd v-for="(guard, i) in room.guard_contacts" :key="i">{{ [guard.name, guard.phone, guard.email].filter(Boolean).join(' · ') }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">{{ t('server_rooms.freeze') }}</dt>
                    <dd v-if="!room.freeze_periods.length">{{ t('server_rooms.no_freeze') }}</dd>
                    <dd v-for="(period, i) in room.freeze_periods" :key="i">
                        {{ dateTime(period.from) }} – {{ dateTime(period.to) }}<template v-if="period.reason"> · {{ period.reason }}</template>
                    </dd>
                </div>
            </dl>

            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold">{{ t('server_rooms.rules_title') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ t('server_rooms.rules_hint') }}</p>
                    </div>
                    <Button v-if="!adding" variant="outline" size="sm" @click="adding = true">{{ t('server_rooms.new_version') }}</Button>
                </div>

                <form v-if="adding" class="space-y-3 rounded-md border bg-muted/30 p-4" @submit.prevent="publish">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">{{ t('server_rooms.summary') }}</span>
                        <Button v-if="versions.length" type="button" variant="ghost" size="sm" @click="copyCurrent">{{ t('server_rooms.copy_current') }}</Button>
                    </div>
                    <div v-for="(_, i) in form.summary" :key="i" class="flex gap-2">
                        <span class="w-8 pt-2 text-right text-xs text-muted-foreground">{{ i + 1 }}.</span>
                        <Input v-model="form.summary[i]" maxlength="500" />
                        <Button v-if="form.summary.length > 1" type="button" variant="ghost" size="sm" @click="form.summary.splice(i, 1)">{{
                            t('common.delete')
                        }}</Button>
                    </div>
                    <Button v-if="form.summary.length < 10" type="button" variant="outline" size="sm" @click="form.summary.push('')">{{
                        t('server_rooms.add_line')
                    }}</Button>
                    <InputError :message="form.errors.summary" />
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="grid gap-1">
                            <Label for="effective_on" required>{{ t('server_rooms.effective_on') }}</Label>
                            <Input id="effective_on" v-model="form.effective_on" type="date" required />
                        </div>
                        <div class="grid gap-1">
                            <Label for="received_from">{{ t('server_rooms.received_from') }}</Label>
                            <Input id="received_from" v-model="form.received_from" maxlength="255" :placeholder="t('server_rooms.received_from_placeholder')" />
                        </div>
                        <div class="grid gap-1">
                            <Label for="received_on">{{ t('server_rooms.received_on') }}</Label>
                            <Input id="received_on" v-model="form.received_on" type="date" />
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <Label for="rules_file">{{ t('server_rooms.file') }}</Label>
                        <input id="rules_file" type="file" accept="application/pdf" class="text-sm" @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                        <InputError :message="form.errors.file" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="rule_note">{{ t('server_rooms.note') }}</Label>
                        <Input id="rule_note" v-model="form.note" maxlength="2000" />
                    </div>
                    <div class="flex gap-2">
                        <Button :disabled="form.processing">{{ t('server_rooms.publish') }}</Button>
                        <Button v-if="versions.length" type="button" variant="ghost" @click="adding = false">{{ t('common.cancel') }}</Button>
                    </div>
                </form>

                <p v-if="!versions.length" class="text-sm text-amber-700">{{ t('server_rooms.no_versions') }}</p>
                <article v-for="v in versions" :key="v.id" class="space-y-2 rounded-md border p-4 text-sm" :class="{ 'opacity-70': versionState(v) === 'superseded' }">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ t('server_rooms.version', { version: v.version }) }}</span>
                        <span
                            class="rounded px-1.5 text-xs"
                            :class="{
                                'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200': versionState(v) === 'in_effect',
                                'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200': versionState(v) === 'upcoming',
                                'bg-muted': versionState(v) === 'superseded',
                            }"
                            >{{ versionState(v) === 'upcoming' ? t('server_rooms.upcoming', { date: v.effective_on }) : t(`server_rooms.${versionState(v)}`) }}</span
                        >
                        <span class="text-xs text-muted-foreground">{{ t('server_rooms.effective_on') }} {{ v.effective_on }}</span>
                        <a v-if="v.file" :href="v.file" target="_blank" class="ml-auto text-xs text-primary hover:underline">{{ t('server_rooms.open_file') }}</a>
                    </div>
                    <ol class="list-decimal space-y-0.5 pl-6">
                        <li v-for="(line, i) in v.summary" :key="i">{{ line }}</li>
                    </ol>
                    <p class="text-xs text-muted-foreground">
                        {{ t('server_rooms.recorded_by', { name: v.created_by_name ?? '-', date: dateTime(v.created_at) }) }}
                        <template v-if="v.received_from"> · {{ t('server_rooms.received', { from: v.received_from, date: v.received_on ?? '-' }) }}</template>
                    </p>
                    <p v-if="v.note" class="text-xs">{{ v.note }}</p>
                </article>
            </section>
        </div>
    </AppLayout>
</template>
