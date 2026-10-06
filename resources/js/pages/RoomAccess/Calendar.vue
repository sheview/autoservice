<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import RoomRequestStatusBadge from '@/components/RoomRequestStatusBadge.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Entry {
    ulid: string | null;
    request_no: string | null;
    requester_name: string | null;
    status: string;
    recurring: boolean;
    room: string | null;
    room_ulid: string | null;
    customer: string;
    start: string;
    end: string;
}

const props = defineProps<{
    from: string;
    to: string;
    month: string;
    room: string | null;
    entries: Entry[];
    freezes: { room: string; from: string; to: string; reason: string | null }[];
    holidays: Record<string, string>;
    rooms: { ulid: string; name: string; customer: string }[];
    can: { create: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('room_requests.title'), href: route('room-access.requests.index') },
    { title: t('room_calendar.title'), href: route('room-access.calendar') },
];
const weekdays = computed(() => ((page.props.translations as Record<string, any>)?.room_calendar?.weekdays ?? []) as string[]);

const pad = (n: number) => String(n).padStart(2, '0');
const ymd = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const [year, monthIndex] = props.month.split('-').map(Number);
const go = (month: string | null, room: string | null = props.room) =>
    router.get(route('room-access.calendar'), { month: month ?? undefined, room: room ?? undefined }, { preserveScroll: true });
const shift = (by: number) => {
    const d = new Date(year, monthIndex - 1 + by, 1);
    go(`${d.getFullYear()}-${pad(d.getMonth() + 1)}`);
};
const monthLabel = new Date(year, monthIndex - 1, 1).toLocaleDateString('th-TH-u-ca-gregory', { month: 'long', year: 'numeric' });

// Weeks from Monday; days outside the month are blank.
const cells = computed(() => {
    const first = new Date(year, monthIndex - 1, 1);
    const days = new Date(year, monthIndex, 0).getDate();
    const lead = (first.getDay() + 6) % 7;
    const list: (string | null)[] = Array.from({ length: lead }, () => null);
    for (let d = 1; d <= days; d++) list.push(ymd(new Date(year, monthIndex - 1, d)));
    while (list.length % 7) list.push(null);
    return list;
});

const byDay = computed(() => {
    const map: Record<string, Entry[]> = {};
    for (const entry of props.entries) {
        // An entry may run over several days (a one-off request across midnight).
        const start = new Date(entry.start);
        const end = new Date(entry.end);
        for (let d = new Date(start.getFullYear(), start.getMonth(), start.getDate()); d < end; d.setDate(d.getDate() + 1)) {
            (map[ymd(d)] ??= []).push(entry);
        }
    }
    return map;
});
const frozen = (day: string) =>
    props.freezes.filter((f) => new Date(f.from) < new Date(`${day}T23:59:59`) && new Date(f.to) > new Date(`${day}T00:00:00`));

const today = ymd(new Date());
const selected = ref<string | null>(props.entries.length && byDay.value[today] ? today : null);
const time = (iso: string) => new Date(iso).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
const tone: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    approved: 'bg-blue-100 text-blue-900 dark:bg-blue-950 dark:text-blue-200',
    inside: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-200',
    exited: 'bg-muted text-muted-foreground',
    overdue: 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200',
};
const label = (e: Entry) => `${time(e.start)} ${e.room ?? ''}${e.requester_name ? ` · ${e.requester_name}` : ` · ${t('room_calendar.taken')}`}`;
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('room_calendar.title')" />

        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading :title="t('room_calendar.title')" :description="t('room_calendar.description')" />
                <Button v-if="can.create" as-child>
                    <Link :href="route('room-access.requests.create')">{{ t('room_calendar.new_request') }}</Link>
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" @click="shift(-1)">‹ {{ t('room_calendar.prev') }}</Button>
                <span class="min-w-40 text-center font-semibold">{{ monthLabel }}</span>
                <Button variant="outline" size="sm" @click="shift(1)">{{ t('room_calendar.next') }} ›</Button>
                <Button variant="ghost" size="sm" @click="go(null)">{{ t('room_calendar.today') }}</Button>
                <select :value="room" :class="selectClass" class="ml-auto" @change="go(month, ($event.target as HTMLSelectElement).value || null)">
                    <option value="">{{ t('room_calendar.all_rooms') }}</option>
                    <option v-for="r in rooms" :key="r.ulid" :value="r.ulid">{{ r.customer }} · {{ r.name }}</option>
                </select>
            </div>

            <div class="grid grid-cols-7 overflow-hidden rounded-md border text-xs">
                <div v-for="w in weekdays" :key="w" class="border-b bg-muted/50 px-2 py-1 text-center font-medium">{{ w }}</div>
                <template v-for="(day, i) in cells" :key="i">
                    <div v-if="!day" class="min-h-24 border-b border-l bg-muted/20" />
                    <button
                        v-else
                        type="button"
                        class="min-h-24 space-y-0.5 border-b border-l p-1 text-left align-top hover:bg-muted/40"
                        :class="[selected === day ? 'ring-2 ring-primary ring-inset' : '', holidays[day] || frozen(day).length ? 'bg-red-50/60 dark:bg-red-950/20' : '']"
                        @click="selected = day"
                    >
                        <div class="flex items-center justify-between gap-1">
                            <span :class="day === today ? 'rounded-full bg-primary px-1.5 text-primary-foreground' : ''">{{ Number(day.slice(8)) }}</span>
                            <span v-if="frozen(day).length" class="truncate rounded bg-red-600 px-1 text-[10px] text-white">{{ t('room_calendar.freeze') }}</span>
                        </div>
                        <div v-if="holidays[day]" class="truncate text-[10px] text-red-700 dark:text-red-300" :title="holidays[day]">{{ holidays[day] }}</div>
                        <div v-for="(e, j) in (byDay[day] ?? []).slice(0, 3)" :key="j" class="truncate rounded px-1" :class="tone[e.status]" :title="label(e)">
                            {{ label(e) }}
                        </div>
                        <div v-if="(byDay[day] ?? []).length > 3" class="text-muted-foreground">
                            {{ t('room_calendar.more', { count: (byDay[day] ?? []).length - 3 }) }}
                        </div>
                    </button>
                </template>
            </div>

            <section v-if="selected" class="space-y-2 rounded-md border p-4">
                <h3 class="text-sm font-semibold">{{ t('room_calendar.day_title', { date: selected }) }}</h3>
                <p v-if="holidays[selected]" class="text-sm text-red-700 dark:text-red-300">{{ t('room_calendar.holiday') }}: {{ holidays[selected] }}</p>
                <p v-for="(f, i) in frozen(selected)" :key="i" class="text-sm text-red-700 dark:text-red-300">
                    {{ t('room_calendar.freeze') }} {{ f.room }}: {{ dateTime(f.from) }} – {{ dateTime(f.to) }} ({{ f.reason ?? '-' }})
                </p>
                <p v-if="!(byDay[selected] ?? []).length" class="text-sm text-muted-foreground">{{ t('room_calendar.nothing') }}</p>
                <ul class="divide-y text-sm">
                    <li v-for="(e, i) in byDay[selected] ?? []" :key="i" class="flex flex-wrap items-center gap-2 py-2">
                        <span class="font-mono">{{ time(e.start) }}–{{ time(e.end) }}</span>
                        <span>{{ e.customer }} · {{ e.room }}</span>
                        <RoomRequestStatusBadge :status="e.status" />
                        <span v-if="e.recurring" class="rounded bg-muted px-1.5 text-xs">{{ t('room_calendar.recurring') }}</span>
                        <Link v-if="e.ulid" :href="route('room-access.requests.show', e.ulid)" class="hover:underline">
                            <span class="font-mono">{{ e.request_no }}</span> · {{ e.requester_name }}
                        </Link>
                        <span v-else class="text-muted-foreground">{{ t('room_calendar.taken') }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
