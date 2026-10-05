<script setup lang="ts">
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
import { CircleCheck, CircleX, DoorOpen } from 'lucide-vue-next';

/**
 * The permit at the guard's counter (its QR link), without signing in: whether it is valid now,
 * which room of which customer, when, who goes in (names only), what equipment, who approved, and
 * the rules accepted. Anything wrong reads as "not found".
 */
defineProps<{
    permit: {
        company: { name: string } | null;
        request: { request_no: string; status: string; requester_name: string | null; purpose: string; planned_start: string; planned_end: string; entered_at: string | null; exited_at: string | null };
        valid: boolean;
        room: { name: string | null; location: string | null; site: string | null };
        customer: string;
        people: { name: string; company: string | null }[];
        items: { name: string; serial_number: string | null; quantity: number; direction: string }[];
        approval: { name: string | null; at: string } | null;
        acceptance: { customer: string | null; room: string | null; version: number | null; at: string; name: string } | null;
        expires_at: string | null;
    } | null;
}>();
</script>

<template>
    <Head :title="t('room_permit.title')" />

    <div class="min-h-svh bg-gradient-to-b from-violet-50 to-white px-4 py-8 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-2xl space-y-5">
            <div class="space-y-2 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 text-white shadow-lg">
                    <DoorOpen class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('room_permit.title') }}</h1>
                <p v-if="permit?.company" class="text-sm font-semibold text-violet-700 dark:text-violet-300">{{ permit.company.name }}</p>
            </div>

            <div v-if="!permit" class="rounded-2xl border bg-background p-6 text-center text-muted-foreground">{{ t('room_permit.not_found') }}</div>

            <template v-else>
                <div
                    class="flex items-center gap-3 rounded-2xl border-2 p-5"
                    :class="permit.valid ? 'border-green-400 bg-green-50 dark:bg-green-950' : 'border-red-400 bg-red-50 dark:bg-red-950'"
                >
                    <component :is="permit.valid ? CircleCheck : CircleX" class="size-10 shrink-0" :class="permit.valid ? 'text-green-600' : 'text-red-600'" />
                    <div>
                        <div class="text-xl font-bold">{{ permit.valid ? t('room_permit.valid') : t('room_permit.invalid') }}</div>
                        <div class="text-sm">
                            {{ t(`room_requests.statuses.${permit.request.status}`) }} · <span class="font-mono">{{ permit.request.request_no }}</span>
                        </div>
                    </div>
                </div>

                <dl class="grid gap-3 rounded-2xl border bg-background p-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('room_permit.place') }}</dt>
                        <dd class="font-semibold">{{ permit.customer }} · {{ permit.room.name }}</dd>
                        <dd class="text-xs">{{ [permit.room.site, permit.room.location].filter(Boolean).join(' · ') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('room_permit.period') }}</dt>
                        <dd class="font-semibold">{{ dateTime(permit.request.planned_start) }} – {{ dateTime(permit.request.planned_end) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('room_permit.requester') }}</dt>
                        <dd>{{ permit.request.requester_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">{{ t('room_permit.approved_by') }}</dt>
                        <dd>{{ permit.approval ? `${permit.approval.name} · ${dateTime(permit.approval.at)}` : '-' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">{{ t('room_permit.purpose') }}</dt>
                        <dd>{{ permit.request.purpose }}</dd>
                    </div>
                    <div v-if="permit.request.entered_at" class="sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">{{ t('room_permit.actual') }}</dt>
                        <dd>{{ dateTime(permit.request.entered_at) }} – {{ permit.request.exited_at ? dateTime(permit.request.exited_at) : '…' }}</dd>
                    </div>
                </dl>

                <section class="space-y-2 rounded-2xl border bg-background p-5">
                    <h2 class="text-sm font-semibold">{{ t('room_permit.people', { count: permit.people.length }) }}</h2>
                    <ul class="divide-y text-sm">
                        <li v-for="(p, i) in permit.people" :key="i" class="py-1.5">
                            {{ i + 1 }}. {{ p.name }} <span v-if="p.company" class="text-muted-foreground">· {{ p.company }}</span>
                        </li>
                    </ul>
                </section>

                <section v-if="permit.items.length" class="space-y-2 rounded-2xl border bg-background p-5">
                    <h2 class="text-sm font-semibold">{{ t('room_permit.items') }}</h2>
                    <ul class="divide-y text-sm">
                        <li v-for="(item, i) in permit.items" :key="i" class="py-1.5">
                            {{ item.name }} × {{ item.quantity }}
                            <span v-if="item.serial_number" class="font-mono text-xs">SN {{ item.serial_number }}</span>
                            <span class="text-xs text-muted-foreground"> · {{ t(`room_requests.directions.${item.direction}`) }}</span>
                        </li>
                    </ul>
                </section>

                <p v-if="permit.acceptance" class="rounded-2xl border bg-background p-4 text-xs text-muted-foreground">
                    {{
                        permit.acceptance.version
                            ? t('room_requests.acceptance_line', {
                                  customer: permit.acceptance.customer ?? '',
                                  room: permit.acceptance.room ?? '',
                                  version: permit.acceptance.version,
                                  at: dateTime(permit.acceptance.at),
                                  name: permit.acceptance.name,
                              })
                            : t('room_requests.acceptance_line_none', { at: dateTime(permit.acceptance.at), name: permit.acceptance.name })
                    }}
                </p>
                <p v-if="permit.expires_at" class="text-center text-xs text-muted-foreground">{{ t('room_permit.expires', { at: dateTime(permit.expires_at) }) }}</p>
            </template>
        </div>
    </div>
</template>
