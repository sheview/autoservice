<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, Paginated, SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Copy, MessageCircle, Phone, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * The outside technicians who signed up through a company's link. The company that keeps the
 * register approves, corrects and removes them and gives out the link; a company it is shared
 * with finds the approved ones. Searched by name, shop, phone or LINE, by province and by kind
 * of work, then called or messaged on LINE straight from here.
 */
interface Technician {
    ulid: string;
    name: string;
    company: string | null;
    phone: string;
    line_id: string | null;
    email: string | null;
    note: string | null;
    status: 'pending' | 'approved' | 'rejected';
    reviewed_by_name: string | null;
    provinces: string[];
    skills: string[];
    created_at: string | null;
    reviewed_at: string | null;
}

interface Filters {
    company: number | null;
    search: string;
    province: string | null;
    skill: string | null;
    status: string | null;
    sort: string;
    direction: 'asc' | 'desc';
    [key: string]: unknown;
}

const props = defineProps<{
    technicians: Paginated<Technician> | null;
    pending: number;
    filters: Filters;
    sources: { id: number; name: string; own: boolean }[];
    provinces: string[];
    skills: string[];
    can: { manage: boolean };
    signupUrl: string | null;
}>();

const page = usePage<SharedData>();
const errors = computed(() => page.props.errors as Record<string, string>);
const breadcrumbs: BreadcrumbItem[] = [{ title: t('field_technicians.title'), href: route('service.field-technicians.index') }];
const filters = useListFilters('service.field-technicians.index', props.filters);

const sortBy = (column: string) => {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
};
const columns = [
    { key: 'name', sort: 'name' },
    { key: 'contact', sort: null },
    { key: 'provinces', sort: null },
    { key: 'skills', sort: null },
    { key: 'status', sort: 'status' },
    { key: 'created_at', sort: 'created_at' },
];

// The sign-up link: copied, or sent on LINE.
const copied = ref(false);
const copy = async () => {
    if (!props.signupUrl) return;
    await navigator.clipboard.writeText(props.signupUrl);
    copied.value = true;
};
const ownName = computed(() => props.sources.find((s) => s.own)?.name ?? '');
const lineShare = computed(() =>
    props.signupUrl
        ? `https://line.me/R/msg/text/?${encodeURIComponent(`${t('field_technicians.share_text', { company: ownName.value })}\n${props.signupUrl}`)}`
        : '',
);
const lineChat = (id: string) => `https://line.me/R/ti/p/~${encodeURIComponent(id)}`;

const review = (technician: Technician, approve: boolean) =>
    router.post(route('service.field-technicians.review', technician.ulid), { approve }, { preserveScroll: true });
const destroy = (technician: Technician) => {
    if (confirm(t('field_technicians.delete_confirm', { name: technician.name }))) {
        router.delete(route('service.field-technicians.destroy', technician.ulid), { preserveScroll: true });
    }
};

// Correcting one technician, in a panel above the list.
const editing = ref<Technician | null>(null);
const form = useForm({ name: '', company: '', phone: '', line_id: '', email: '', note: '', provinces: [] as string[], skills: [] as string[] });
const edit = (technician: Technician) => {
    editing.value = technician;
    form.defaults({
        name: technician.name,
        company: technician.company ?? '',
        phone: technician.phone,
        line_id: technician.line_id ?? '',
        email: technician.email ?? '',
        note: technician.note ?? '',
        provinces: [...technician.provinces],
        skills: [...technician.skills],
    });
    form.reset();
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
const save = () =>
    editing.value &&
    form.put(route('service.field-technicians.update', editing.value.ulid), { preserveScroll: true, onSuccess: () => (editing.value = null) });
const addProvince = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    if (select.value && !form.provinces.includes(select.value)) form.provinces.push(select.value);
    select.value = '';
};
const toggleSkill = (skill: string) => (form.skills = form.skills.includes(skill) ? form.skills.filter((s) => s !== skill) : [...form.skills, skill]);

const statusClass: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
    approved: 'bg-green-100 text-green-900 dark:bg-green-950 dark:text-green-100',
    rejected: 'bg-muted text-muted-foreground',
};
const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('field_technicians.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('field_technicians.title')" :description="t('field_technicians.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <p v-if="sources.length === 0" class="rounded-md border p-6 text-center text-muted-foreground">{{ t('field_technicians.no_source') }}</p>

            <template v-else>
                <!-- The link to send to technicians (the company that keeps the register) -->
                <section v-if="signupUrl" class="space-y-2 rounded-md border bg-violet-50/60 p-4 dark:bg-violet-950/30">
                    <h3 class="text-sm font-semibold">{{ t('field_technicians.signup_link') }}</h3>
                    <p class="text-xs text-muted-foreground">{{ t('field_technicians.signup_hint') }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="min-w-0 flex-1 truncate rounded bg-background px-3 py-2 text-xs">{{ signupUrl }}</code>
                        <Button size="sm" variant="outline" @click="copy"
                            ><Copy class="size-4" />{{ copied ? t('field_technicians.copied') : t('field_technicians.copy') }}</Button
                        >
                        <Button size="sm" variant="outline" as-child>
                            <a :href="lineShare" target="_blank" rel="noopener"
                                ><MessageCircle class="size-4" />{{ t('field_technicians.send_line') }}</a
                            >
                        </Button>
                    </div>
                </section>

                <!-- Correcting one technician -->
                <form v-if="editing" class="grid gap-3 rounded-md border p-4 sm:grid-cols-2" @submit.prevent="save">
                    <div class="flex items-center justify-between sm:col-span-2">
                        <h3 class="font-semibold">{{ t('field_technicians.edit') }}: {{ editing.name }}</h3>
                        <button type="button" :aria-label="t('field_technicians.cancel')" @click="editing = null"><X class="size-4" /></button>
                    </div>
                    <label class="grid gap-1 text-sm">
                        <span>{{ t('technician_signup.name') }} <span class="text-red-600">*</span></span>
                        <Input v-model="form.name" required maxlength="255" />
                        <InputError :message="form.errors.name" />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span>{{ t('technician_signup.company') }}</span>
                        <Input v-model="form.company" maxlength="255" />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span>{{ t('technician_signup.phone') }} <span class="text-red-600">*</span></span>
                        <Input v-model="form.phone" type="tel" required maxlength="50" />
                        <InputError :message="form.errors.phone" />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span>{{ t('technician_signup.line_id') }}</span>
                        <Input v-model="form.line_id" maxlength="100" />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span>{{ t('technician_signup.email') }}</span>
                        <Input v-model="form.email" type="email" maxlength="255" />
                        <InputError :message="form.errors.email" />
                    </label>
                    <label class="grid gap-1 text-sm">
                        <span>{{ t('field_technicians.note') }}</span>
                        <Input v-model="form.note" maxlength="2000" />
                    </label>
                    <div class="grid gap-1 text-sm sm:col-span-2">
                        <span>{{ t('technician_signup.provinces') }} <span class="text-red-600">*</span></span>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="province in form.provinces"
                                :key="province"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-full bg-violet-600 px-3 py-1 text-xs text-white"
                                @click="form.provinces = form.provinces.filter((p) => p !== province)"
                            >
                                {{ province }}<X class="size-3" />
                            </button>
                            <select :class="selectClass" @change="addProvince">
                                <option value="">+ {{ t('technician_signup.provinces') }}</option>
                                <option v-for="province in provinces.filter((p) => !form.provinces.includes(p))" :key="province" :value="province">
                                    {{ province }}
                                </option>
                            </select>
                        </div>
                        <InputError :message="form.errors.provinces" />
                    </div>
                    <div class="grid gap-1 text-sm sm:col-span-2">
                        <span>{{ t('technician_signup.skills') }} <span class="text-red-600">*</span></span>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="skill in skills"
                                :key="skill"
                                type="button"
                                :class="[
                                    'rounded-full border px-3 py-1 text-xs',
                                    form.skills.includes(skill) ? 'border-violet-600 bg-violet-600 text-white' : '',
                                ]"
                                @click="toggleSkill(skill)"
                            >
                                {{ t(`field_technicians.skills.${skill}`) }}
                            </button>
                        </div>
                        <InputError :message="form.errors.skills" />
                    </div>
                    <div class="flex gap-2 sm:col-span-2">
                        <Button :disabled="form.processing">{{ t('field_technicians.save') }}</Button>
                        <Button type="button" variant="outline" @click="editing = null">{{ t('field_technicians.cancel') }}</Button>
                    </div>
                </form>

                <div class="flex flex-wrap items-center gap-3">
                    <select v-if="sources.length > 1" v-model="filters.company" :class="selectClass" :aria-label="t('field_technicians.source')">
                        <option v-for="source in sources" :key="source.id" :value="source.id">
                            {{ t('field_technicians.source') }} {{ source.name }} ({{
                                source.own ? t('field_technicians.own') : t('field_technicians.shared')
                            }})
                        </option>
                    </select>
                    <Input v-model="filters.search" type="search" class="max-w-xs" :placeholder="t('field_technicians.search_placeholder')" />
                    <select v-model="filters.province" :class="selectClass" :aria-label="t('technician_signup.provinces')">
                        <option :value="null">{{ t('field_technicians.all_provinces') }}</option>
                        <option v-for="province in provinces" :key="province" :value="province">{{ province }}</option>
                    </select>
                    <select v-model="filters.skill" :class="selectClass" :aria-label="t('technician_signup.skills')">
                        <option :value="null">{{ t('field_technicians.all_skills') }}</option>
                        <option v-for="skill in skills" :key="skill" :value="skill">{{ t(`field_technicians.skills.${skill}`) }}</option>
                    </select>
                    <select v-if="can.manage" v-model="filters.status" :class="selectClass" :aria-label="t('field_technicians.columns.status')">
                        <option :value="null">{{ t('field_technicians.all_statuses') }}</option>
                        <option v-for="status in ['pending', 'approved', 'rejected']" :key="status" :value="status">
                            {{ t(`field_technicians.statuses.${status}`) }}
                        </option>
                    </select>
                    <button
                        v-if="can.manage && pending > 0"
                        type="button"
                        class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                        @click="filters.status = 'pending'"
                    >
                        {{ t('field_technicians.pending_count', { count: pending }) }}
                    </button>
                </div>
                <InputError :message="errors.approve" />

                <div v-if="technicians" class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th v-for="column in columns" :key="column.key" class="px-4 py-2 font-medium">
                                    <button v-if="column.sort" type="button" class="flex items-center gap-1" @click="sortBy(column.sort)">
                                        {{ t(`field_technicians.columns.${column.key}`) }}
                                        <template v-if="filters.sort === column.sort">
                                            <ArrowUp v-if="filters.direction === 'asc'" class="h-3 w-3" />
                                            <ArrowDown v-else class="h-3 w-3" />
                                        </template>
                                    </button>
                                    <span v-else>{{ t(`field_technicians.columns.${column.key}`) }}</span>
                                </th>
                                <th v-if="can.manage" class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="technician in technicians.data" :key="technician.ulid" class="border-t align-top">
                                <td class="px-4 py-2">
                                    <div class="font-medium">{{ technician.name }}</div>
                                    <div v-if="technician.company" class="text-xs text-muted-foreground">{{ technician.company }}</div>
                                    <div v-if="technician.note" class="mt-1 max-w-xs whitespace-pre-line text-xs text-muted-foreground">
                                        {{ technician.note }}
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2">
                                    <a :href="`tel:${technician.phone}`" class="flex items-center gap-1 text-primary hover:underline"
                                        ><Phone class="size-3.5" />{{ technician.phone }}</a
                                    >
                                    <a
                                        v-if="technician.line_id"
                                        :href="lineChat(technician.line_id)"
                                        target="_blank"
                                        rel="noopener"
                                        class="flex items-center gap-1 text-green-700 hover:underline dark:text-green-400"
                                        ><MessageCircle class="size-3.5" />{{ technician.line_id }}</a
                                    >
                                    <div v-if="technician.email" class="text-xs text-muted-foreground">{{ technician.email }}</div>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex max-w-xs flex-wrap gap-1">
                                        <span
                                            v-for="province in technician.provinces"
                                            :key="province"
                                            class="rounded bg-muted px-1.5 py-0.5 text-xs"
                                            >{{ province }}</span
                                        >
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex max-w-xs flex-wrap gap-1">
                                        <span v-for="skill in technician.skills" :key="skill" class="rounded border px-1.5 py-0.5 text-xs">{{
                                            t(`field_technicians.skills.${skill}`)
                                        }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <span :class="['whitespace-nowrap rounded-full px-2 py-0.5 text-xs', statusClass[technician.status]]">{{
                                        t(`field_technicians.statuses.${technician.status}`)
                                    }}</span>
                                    <div v-if="technician.reviewed_by_name && can.manage" class="mt-1 text-xs text-muted-foreground">
                                        {{ technician.reviewed_by_name }}
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">
                                    {{ technician.created_at ? dateTime(technician.created_at) : '-' }}
                                </td>
                                <td v-if="can.manage" class="whitespace-nowrap px-4 py-2 text-right">
                                    <button
                                        v-if="technician.status !== 'approved'"
                                        type="button"
                                        class="text-green-700 hover:underline dark:text-green-400"
                                        @click="review(technician, true)"
                                    >
                                        {{ t('field_technicians.approve') }}
                                    </button>
                                    <button
                                        v-if="technician.status !== 'rejected'"
                                        type="button"
                                        class="ml-3 text-amber-700 hover:underline dark:text-amber-400"
                                        @click="review(technician, false)"
                                    >
                                        {{ t('field_technicians.reject') }}
                                    </button>
                                    <button type="button" class="ml-3 text-primary hover:underline" @click="edit(technician)">
                                        {{ t('field_technicians.edit') }}
                                    </button>
                                    <button type="button" class="ml-3 text-red-700 hover:underline dark:text-red-400" @click="destroy(technician)">
                                        {{ t('field_technicians.delete') }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="technicians.data.length === 0">
                                <td :colspan="columns.length + (can.manage ? 1 : 0)" class="px-4 py-8 text-center text-muted-foreground">
                                    {{ t('common.no_results') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination v-if="technicians" :paginator="technicians" />
            </template>
        </div>
    </AppLayout>
</template>
