<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TicketStatusBadge from '@/components/TicketStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, CircleCheck, Lock, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface WorkItem {
    key: string;
    kind: 'ticket' | 'loan' | 'personal';
    id?: number;
    title: string;
    date: string;
    end_date?: string | null;
    time: string | null;
    end_time?: string | null;
    all_day?: boolean;
    notes?: string | null;
    when: string;
    status: string | null;
    priority: string | null;
    open: boolean;
    overdue: boolean;
    href: string | null;
    // Tickets only: the one-page close, while the job can be closed there.
    close_href?: string | null;
}

const props = defineProps<{
    month: string;
    from: string;
    to: string;
    today: string;
    events: WorkItem[];
    todo: WorkItem[];
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('my_work.title'), href: route('service.my-work') }];

// --- dates as plain Y-m-d strings (the server already put them in Bangkok time) ---
const addDays = (date: string, days: number) => {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const monthLabel = computed(() =>
    new Date(`${props.month}-01T00:00:00`).toLocaleDateString('th-TH-u-ca-gregory', { month: 'long', year: 'numeric' }),
);
const dayLabel = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('th-TH-u-ca-gregory', { weekday: 'long', day: 'numeric', month: 'long' });
const shiftMonth = (by: number) => {
    const d = new Date(`${props.month}-01T00:00:00`);
    d.setMonth(d.getMonth() + by);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};
const go = (month: string | null) => router.get(route('service.my-work'), month ? { month } : {}, { preserveScroll: true, preserveState: true });

// --- the calendar grid: whole weeks from `from` to `to` ---
const days = computed(() => {
    const list: string[] = [];
    for (let d = props.from; d <= props.to; d = addDays(d, 1)) list.push(d);
    return list;
});
const byDay = computed(() => {
    const map: Record<string, WorkItem[]> = {};
    for (const e of props.events) (map[e.date] ??= []).push(e);
    return map;
});
const inMonth = (date: string) => date.startsWith(props.month);

const selected = ref<string>(props.today.startsWith(props.month) ? props.today : `${props.month}-01`);
const selectedItems = computed(() => byDay.value[selected.value] ?? []);

// --- to-do buckets ---
const weekEnd = computed(() => addDays(props.today, 7));
const buckets = computed(() => [
    { key: 'overdue', items: props.todo.filter((i) => i.overdue) },
    { key: 'today', items: props.todo.filter((i) => !i.overdue && i.date <= props.today) },
    { key: 'week', items: props.todo.filter((i) => !i.overdue && i.date > props.today && i.date <= weekEnd.value) },
    { key: 'later', items: props.todo.filter((i) => !i.overdue && i.date > weekEnd.value) },
]);

// --- colours: few, by kind; an overdue job stands out ---
const chipClass = (item: WorkItem) =>
    item.overdue
        ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200'
        : {
              ticket: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-100',
              loan: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
              personal: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100',
          }[item.kind];
const dotClass = (item: WorkItem) =>
    item.overdue ? 'bg-red-500' : { ticket: 'bg-sky-500', loan: 'bg-amber-500', personal: 'bg-emerald-500' }[item.kind];

// --- own appointments ---
const editing = ref<WorkItem | 'new' | null>(null);
const form = useForm({ title: '', date: '', all_day: false as boolean, start_time: '09:00', end_time: '10:00', notes: '' });
const openNew = (date: string) => {
    selected.value = date;
    editing.value = 'new';
    form.defaults({ title: '', date, all_day: false, start_time: '09:00', end_time: '10:00', notes: '' });
    form.reset();
    form.clearErrors();
};
const openEdit = (item: WorkItem) => {
    editing.value = item;
    form.defaults({
        title: item.title,
        date: item.date,
        all_day: !!item.all_day,
        start_time: item.time ?? '09:00',
        end_time: item.end_time ?? '',
        notes: item.notes ?? '',
    });
    form.reset();
    form.clearErrors();
};
const save = () => {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('service.my-work.events.store'), options);
    else if (editing.value?.id) form.put(route('service.my-work.events.update', editing.value.id), options);
};
const remove = (item: WorkItem) =>
    item.id &&
    confirm(t('my_work.confirm_delete', { title: item.title })) &&
    router.delete(route('service.my-work.events.destroy', item.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });

const timeText = (item: WorkItem) =>
    item.time ? (item.end_time ? `${item.time}–${item.end_time}` : item.time) : item.all_day ? t('my_work.all_day') : '';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('my_work.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('my_work.title')" :description="t('my_work.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <!-- At a glance -->
            <div class="grid grid-cols-3 gap-3 sm:max-w-xl">
                <div
                    v-for="b in buckets.slice(0, 3)"
                    :key="b.key"
                    class="rounded-lg border p-3"
                    :class="b.key === 'overdue' && b.items.length ? 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40' : ''"
                >
                    <div class="text-sm text-muted-foreground">{{ t(`my_work.${b.key}`) }}</div>
                    <div
                        class="text-2xl font-semibold tabular-nums"
                        :class="{ 'text-red-700 dark:text-red-400': b.key === 'overdue' && b.items.length }"
                    >
                        {{ b.items.length }}
                    </div>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(280px,360px)_1fr]">
                <!-- To do -->
                <section class="order-2 space-y-4 lg:order-1">
                    <h3 class="font-semibold">{{ t('my_work.todo') }}</h3>
                    <p v-if="!todo.length" class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {{ t('my_work.nothing') }}
                    </p>
                    <template v-for="b in buckets" :key="b.key">
                        <div v-if="b.items.length" class="space-y-2">
                            <div
                                class="text-xs font-semibold uppercase tracking-wide"
                                :class="b.key === 'overdue' ? 'text-red-700 dark:text-red-400' : 'text-muted-foreground'"
                            >
                                {{ t(`my_work.${b.key}`) }} · {{ b.items.length }}
                            </div>
                            <ul class="space-y-2">
                                <li v-for="item in b.items" :key="item.key" class="flex items-stretch gap-2">
                                    <component
                                        :is="item.href ? Link : 'button'"
                                        :href="item.href ?? undefined"
                                        type="button"
                                        class="flex w-full items-start gap-3 rounded-lg border p-3 text-left text-sm transition hover:bg-muted/50"
                                        @click="!item.href && openEdit(item)"
                                    >
                                        <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="dotClass(item)" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate">{{ item.title }}</span>
                                            <span class="block text-xs text-muted-foreground">
                                                {{ t(`my_work.when.${item.when}`) }} ·
                                                {{
                                                    new Date(`${item.date}T00:00:00`).toLocaleDateString('th-TH-u-ca-gregory', {
                                                        day: 'numeric',
                                                        month: 'short',
                                                    })
                                                }}
                                                <template v-if="timeText(item)"> {{ timeText(item) }}</template>
                                            </span>
                                        </span>
                                        <TicketStatusBadge v-if="item.kind === 'ticket' && item.status" :status="item.status" />
                                    </component>
                                    <Link
                                        v-if="item.close_href"
                                        :href="item.close_href"
                                        :title="t('close.open')"
                                        :aria-label="t('close.open')"
                                        class="flex shrink-0 items-center rounded-lg bg-primary px-3 text-primary-foreground transition hover:bg-primary/90"
                                    >
                                        <CircleCheck class="h-5 w-5" />
                                    </Link>
                                </li>
                            </ul>
                        </div>
                    </template>
                </section>

                <!-- Calendar -->
                <section class="order-1 space-y-4 lg:order-2">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-1">
                            <Button size="icon" variant="ghost" :aria-label="t('my_work.prev')" @click="go(shiftMonth(-1))"
                                ><ChevronLeft class="h-5 w-5"
                            /></Button>
                            <h3 class="min-w-40 text-center text-lg font-semibold">{{ monthLabel }}</h3>
                            <Button size="icon" variant="ghost" :aria-label="t('my_work.next')" @click="go(shiftMonth(1))"
                                ><ChevronRight class="h-5 w-5"
                            /></Button>
                            <Button size="sm" variant="outline" class="ml-2" @click="go(null)">{{ t('my_work.this_month') }}</Button>
                        </div>
                        <Button size="sm" @click="openNew(selected)"><Plus class="h-4 w-4" />{{ t('my_work.add') }}</Button>
                    </div>

                    <div class="overflow-hidden rounded-lg border">
                        <div class="grid grid-cols-7 border-b bg-muted/40 text-center text-xs font-semibold text-muted-foreground">
                            <div v-for="i in 7" :key="i" class="py-2" :class="{ 'text-red-600': i === 1 }">{{ t(`my_work.weekdays.${i - 1}`) }}</div>
                        </div>
                        <div class="grid grid-cols-7">
                            <button
                                v-for="day in days"
                                :key="day"
                                type="button"
                                class="group relative min-h-16 border-b border-r p-1 text-left align-top transition hover:bg-muted/40 sm:min-h-28 sm:p-1.5 [&:nth-child(7n)]:border-r-0"
                                :class="[
                                    !inMonth(day) && 'bg-muted/20 text-muted-foreground',
                                    selected === day && 'bg-primary/5 ring-2 ring-inset ring-primary',
                                ]"
                                @click="selected = day"
                                @dblclick="openNew(day)"
                            >
                                <span
                                    class="inline-flex size-6 items-center justify-center rounded-full text-xs sm:text-sm"
                                    :class="day === today ? 'bg-primary font-semibold text-primary-foreground' : ''"
                                >
                                    {{ Number(day.slice(8)) }}
                                </span>
                                <!-- Phone: dots; wider: the first few items -->
                                <div class="mt-1 flex flex-wrap gap-0.5 sm:hidden">
                                    <span
                                        v-for="item in (byDay[day] ?? []).slice(0, 4)"
                                        :key="item.key"
                                        class="size-1.5 rounded-full"
                                        :class="dotClass(item)"
                                    />
                                </div>
                                <div class="mt-1 hidden space-y-0.5 sm:block">
                                    <div
                                        v-for="item in (byDay[day] ?? []).slice(0, 3)"
                                        :key="item.key"
                                        class="truncate rounded px-1 py-0.5 text-xs leading-tight"
                                        :class="chipClass(item)"
                                    >
                                        <template v-if="item.time">{{ item.time }} </template>{{ item.title }}
                                    </div>
                                    <div v-if="(byDay[day] ?? []).length > 3" class="px-1 text-xs text-muted-foreground">
                                        {{ t('my_work.more', { count: (byDay[day] ?? []).length - 3 }) }}
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="flex flex-wrap gap-4 text-xs text-muted-foreground">
                        <span v-for="kind in ['ticket', 'loan', 'personal'] as const" :key="kind" class="flex items-center gap-1.5">
                            <span
                                class="size-2.5 rounded-full"
                                :class="{ ticket: 'bg-sky-500', loan: 'bg-amber-500', personal: 'bg-emerald-500' }[kind]"
                            />
                            {{ t(`my_work.kinds.${kind}`) }}
                        </span>
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-red-500" />{{ t('my_work.overdue') }}</span>
                    </div>

                    <!-- The chosen day -->
                    <div class="space-y-3 rounded-lg border p-4">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="font-semibold">{{ dayLabel(selected) }}</h4>
                            <Button size="sm" variant="outline" @click="openNew(selected)"><Plus class="h-4 w-4" />{{ t('my_work.add') }}</Button>
                        </div>
                        <p v-if="!selectedItems.length && !editing" class="text-sm text-muted-foreground">{{ t('my_work.no_events') }}</p>
                        <ul class="space-y-2">
                            <li
                                v-for="item in selectedItems"
                                :key="item.key"
                                class="flex items-start gap-3 rounded-md p-2 text-sm"
                                :class="chipClass(item)"
                            >
                                <span class="w-20 shrink-0 tabular-nums">{{ timeText(item) || t(`my_work.when.${item.when}`) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block">{{ item.title }}</span>
                                    <span class="block text-xs opacity-80"
                                        >{{ t(`my_work.kinds.${item.kind}`) }} · {{ t(`my_work.when.${item.when}`) }}</span
                                    >
                                    <span v-if="item.notes" class="block whitespace-pre-line text-xs opacity-80">{{ item.notes }}</span>
                                </span>
                                <Link v-if="item.href" :href="item.href" class="text-xs font-semibold underline-offset-4 hover:underline">{{
                                    t('my_work.open')
                                }}</Link>
                                <button v-else type="button" class="text-xs font-semibold underline-offset-4 hover:underline" @click="openEdit(item)">
                                    {{ t('my_work.edit') }}
                                </button>
                            </li>
                        </ul>

                        <!-- Add / change an own appointment -->
                        <form v-if="editing" class="grid gap-3 border-t pt-4 sm:grid-cols-2" @submit.prevent="save">
                            <div class="flex items-center gap-2 text-xs text-muted-foreground sm:col-span-2">
                                <Lock class="h-3.5 w-3.5" />{{ t('my_work.private_hint') }}
                            </div>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label for="ev_title" required>{{ t('my_work.event_title') }}</Label>
                                <Input id="ev_title" v-model="form.title" :placeholder="t('my_work.event_title_placeholder')" required />
                                <InputError :message="form.errors.title" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="ev_date" required>{{ t('my_work.date') }}</Label>
                                <Input id="ev_date" v-model="form.date" type="date" required />
                                <InputError :message="form.errors.date" />
                            </div>
                            <label class="flex items-center gap-2 self-end pb-2 text-sm">
                                <input v-model="form.all_day" type="checkbox" class="size-4" />
                                {{ t('my_work.all_day') }}
                            </label>
                            <template v-if="!form.all_day">
                                <div class="grid gap-1.5">
                                    <Label for="ev_start" required>{{ t('my_work.start') }}</Label>
                                    <Input id="ev_start" v-model="form.start_time" type="time" required />
                                    <InputError :message="form.errors.start_time" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="ev_end">{{ t('my_work.end') }}</Label>
                                    <Input id="ev_end" v-model="form.end_time" type="time" />
                                    <InputError :message="form.errors.end_time" />
                                </div>
                            </template>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label for="ev_notes">{{ t('my_work.notes') }}</Label>
                                <textarea
                                    id="ev_notes"
                                    v-model="form.notes"
                                    rows="2"
                                    class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                />
                            </div>
                            <div class="flex flex-wrap gap-2 sm:col-span-2">
                                <Button :disabled="form.processing">{{ t('my_work.save') }}</Button>
                                <Button type="button" variant="ghost" @click="editing = null">{{ t('common.cancel') }}</Button>
                                <Button v-if="editing !== 'new'" type="button" variant="ghost" class="ml-auto text-red-600" @click="remove(editing)">
                                    {{ t('my_work.delete') }}
                                </Button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
