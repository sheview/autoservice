<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { CircleCheck, CircleX, DoorOpen, LogIn, LogOut } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * The guards' link of a request, without signing in: the permit, and big buttons to record the
 * team going in and coming out, with the guard's name (kept on this device for next time). When
 * the room asks, the team's acceptance of the rules is confirmed at the door.
 */
const props = defineProps<{
    page: {
        permit: {
            company: { name: string } | null;
            valid: boolean;
            room: { name: string | null; location: string | null; site: string | null };
            customer: string;
            people: { name: string; company: string | null }[];
            items: { name: string; serial_number: string | null; quantity: number; direction: string }[];
            request: { request_no: string; status: string; requester_name: string | null; purpose: string; planned_start: string; planned_end: string; entered_at: string | null; exited_at: string | null };
        };
        rules: { summary: string[]; company_terms: string[]; version: number | null } | null;
        usable: boolean;
    } | null;
}>();

const shared = usePage<SharedData>();
const savedName = (() => {
    try {
        return localStorage.getItem('room-guard-name') ?? '';
    } catch {
        return '';
    }
})();
const form = useForm({ guard_name: savedName, accept: false });
const status = computed(() => props.page?.permit.request.status);
const record = (action: 'enter' | 'exit') => {
    try {
        localStorage.setItem('room-guard-name', form.guard_name);
    } catch {
        // Private mode: the name is just not remembered.
    }
    form.post(`${window.location.pathname}/${action}`, { preserveScroll: true });
};
const errors = computed(() => shared.props.errors as Record<string, string>);
</script>

<template>
    <Head :title="t('room_guard.title')" />

    <div class="min-h-svh bg-gradient-to-b from-violet-50 to-white px-4 py-8 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-2xl space-y-5">
            <div class="space-y-2 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 text-white shadow-lg">
                    <DoorOpen class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('room_guard.title') }}</h1>
                <p v-if="page?.permit.company" class="text-sm font-semibold text-violet-700 dark:text-violet-300">{{ page.permit.company.name }}</p>
            </div>

            <div v-if="!page" class="rounded-2xl border bg-background p-6 text-center text-muted-foreground">{{ t('room_guard.not_found') }}</div>

            <template v-else>
                <p v-if="shared.props.flash.success" class="rounded-2xl bg-green-50 px-4 py-3 text-center font-semibold text-green-800 dark:bg-green-950 dark:text-green-200">
                    {{ shared.props.flash.success }}
                </p>

                <div class="flex items-center gap-3 rounded-2xl border-2 p-5" :class="page.permit.valid ? 'border-green-400 bg-green-50 dark:bg-green-950' : 'border-red-400 bg-red-50 dark:bg-red-950'">
                    <component :is="page.permit.valid ? CircleCheck : CircleX" class="size-10 shrink-0" :class="page.permit.valid ? 'text-green-600' : 'text-red-600'" />
                    <div>
                        <div class="text-xl font-bold">{{ page.permit.valid ? t('room_permit.valid') : t('room_permit.invalid') }}</div>
                        <div class="text-sm">{{ page.permit.customer }} · {{ page.permit.room.name }} · <span class="font-mono">{{ page.permit.request.request_no }}</span></div>
                        <div class="text-sm">{{ dateTime(page.permit.request.planned_start) }} – {{ dateTime(page.permit.request.planned_end) }}</div>
                    </div>
                </div>

                <section class="rounded-2xl border bg-background p-5 text-sm">
                    <p v-if="page.permit.request.exited_at" class="font-semibold">{{ t('room_guard.done', { at: dateTime(page.permit.request.exited_at) }) }}</p>
                    <p v-else-if="page.permit.request.entered_at" class="font-semibold text-violet-700">{{ t('room_guard.inside_since', { at: dateTime(page.permit.request.entered_at) }) }}</p>
                    <p v-else class="text-muted-foreground">{{ t('room_guard.waiting') }}</p>
                    <h2 class="mt-3 font-semibold">{{ t('room_permit.people', { count: page.permit.people.length }) }}</h2>
                    <ul class="divide-y">
                        <li v-for="(p, i) in page.permit.people" :key="i" class="py-1.5">{{ i + 1 }}. {{ p.name }} <span v-if="p.company" class="text-muted-foreground">· {{ p.company }}</span></li>
                    </ul>
                    <template v-if="page.permit.items.length">
                        <h2 class="mt-3 font-semibold">{{ t('room_permit.items') }}</h2>
                        <ul class="divide-y">
                            <li v-for="(item, i) in page.permit.items" :key="i" class="py-1.5">
                                {{ item.name }} × {{ item.quantity }} <span v-if="item.serial_number" class="font-mono text-xs">SN {{ item.serial_number }}</span>
                            </li>
                        </ul>
                    </template>
                </section>

                <p v-if="!page.usable" class="rounded-2xl bg-red-50 p-4 text-center text-red-800 dark:bg-red-950 dark:text-red-200">{{ t('room_guard.unusable') }}</p>

                <form v-else-if="status === 'approved' || status === 'inside'" class="space-y-4 rounded-2xl border bg-background p-5" @submit.prevent>
                    <label class="block space-y-1.5 text-sm">
                        <span class="font-semibold">{{ t('room_guard.guard_name') }} <span class="text-red-600">*</span></span>
                        <input v-model="form.guard_name" required maxlength="255" class="h-12 w-full rounded-xl border border-input bg-transparent px-3 text-base" />
                    </label>
                    <div v-if="status === 'approved' && page.rules" class="space-y-2 rounded-xl bg-muted/50 p-3 text-sm">
                        <div class="font-semibold">{{ t('room_guard.rules') }}</div>
                        <ol class="list-decimal space-y-1 pl-5">
                            <li v-for="(line, i) in [...page.rules.summary, ...page.rules.company_terms]" :key="i">{{ line }}</li>
                        </ol>
                        <label class="flex items-start gap-2 pt-1 font-medium">
                            <input v-model="form.accept" type="checkbox" class="mt-0.5 size-5" />{{ t('room_guard.accept') }}
                        </label>
                    </div>
                    <InputError :message="errors.visit || errors.accept || errors.guard_name" />
                    <button
                        v-if="status === 'approved'"
                        type="button"
                        :disabled="form.processing || !form.guard_name || (!!page.rules && !form.accept)"
                        class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl bg-green-600 text-lg font-semibold text-white disabled:opacity-50"
                        @click="record('enter')"
                    >
                        <LogIn class="size-6" />{{ t('room_guard.enter') }}
                    </button>
                    <button
                        v-else
                        type="button"
                        :disabled="form.processing || !form.guard_name"
                        class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl bg-violet-600 text-lg font-semibold text-white disabled:opacity-50"
                        @click="record('exit')"
                    >
                        <LogOut class="size-6" />{{ t('room_guard.exit') }}
                    </button>
                </form>
            </template>
        </div>
    </div>
</template>
