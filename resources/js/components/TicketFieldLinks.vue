<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';
import { Copy, ExternalLink, Link2, MapPin, MessageCircle, UserSearch } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * A ticket's links for working without an account: an outside technician fills in the job from a
 * phone or an iPad ("work"), or the customer only signs it off ("sign"). Sent by LINE or copied;
 * stopped at any time. What came back waits here until the helpdesk marks it checked (or moves
 * the job on), then closes the job and prints the report as usual.
 */
interface FieldLink {
    id: number;
    mode: 'work' | 'sign';
    holder_name: string;
    holder_company: string | null;
    holder_phone: string | null;
    created_by_name: string | null;
    url: string;
    usable: boolean;
    expires_at: string;
    revoked_at: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    reviewed_by_name: string | null;
}

const props = defineProps<{ ticketUlid: string; links: FieldLink[] }>();

const open = ref(false);
const form = useForm({ mode: 'work' as 'work' | 'sign', holder_name: '', holder_company: '', holder_phone: '', days: 7 });
const create = () =>
    form.post(route('service.tickets.field-links.store', props.ticketUlid), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });

const waiting = computed(() => props.links.filter((l) => l.submitted_at && !l.reviewed_at));
const review = () => router.post(route('service.tickets.field-review', props.ticketUlid), {}, { preserveScroll: true });
const revoke = (link: FieldLink) => {
    if (confirm(t('field_links.revoke_confirm', { name: link.holder_name }))) {
        router.post(route('service.tickets.field-links.revoke', [props.ticketUlid, link.id]), {}, { preserveScroll: true });
    }
};
const copied = ref<number | null>(null);
const copy = async (link: FieldLink) => {
    await navigator.clipboard.writeText(link.url);
    copied.value = link.id;
};
const line = (link: FieldLink) => `https://line.me/R/msg/text/?${encodeURIComponent(`${t(`field_links.share_${link.mode}`)}\n${link.url}`)}`;
const state = (link: FieldLink) => (link.revoked_at ? 'revoked' : link.usable ? 'active' : 'expired');

// An outside technician from the register (this company's, or one shared with it), those in the
// ticket's province first: picking one fills in the name, shop and phone. Hidden for users who
// may not read a register.
interface Pickable {
    ulid: string;
    name: string;
    company: string | null;
    phone: string;
    line_id: string | null;
    provinces: string[];
    in_province: boolean;
    source: string | null;
}
const pickable = ref(true);
const pickSearch = ref('');
const pickProvince = ref<string | null>(null);
const technicians = ref<Pickable[]>([]);
let pickTimer: ReturnType<typeof setTimeout> | undefined;
const loadTechnicians = async () => {
    const response = await fetch(route('service.tickets.field-technicians', { ticket: props.ticketUlid, search: pickSearch.value }), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) {
        pickable.value = false;
        return;
    }
    const data = (await response.json()) as { province: string | null; technicians: Pickable[] };
    pickProvince.value = data.province;
    technicians.value = data.technicians;
};
watch(
    () => open.value && form.mode === 'work',
    (show) => {
        if (show && pickable.value) loadTechnicians();
    },
);
watch(pickSearch, () => {
    clearTimeout(pickTimer);
    pickTimer = setTimeout(loadTechnicians, 300);
});
const pick = (technician: Pickable) => {
    form.holder_name = technician.name;
    form.holder_company = technician.company ?? technician.name;
    form.holder_phone = technician.phone;
};
</script>

<template>
    <section class="space-y-3 rounded-md border p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 text-sm font-semibold"><Link2 class="size-4" />{{ t('field_links.title') }}</h3>
            <Button size="sm" variant="outline" @click="open = !open">{{ t('field_links.new') }}</Button>
        </div>
        <p class="text-xs text-muted-foreground">{{ t('field_links.hint') }}</p>

        <div
            v-if="waiting.length"
            class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
        >
            <span>{{ t('field_links.waiting', { names: waiting.map((l) => l.holder_name).join(', ') }) }}</span>
            <Button size="sm" @click="review">{{ t('field_links.mark_reviewed') }}</Button>
        </div>

        <form v-if="open" class="grid gap-3 rounded-md bg-muted/40 p-3 sm:grid-cols-2" @submit.prevent="create">
            <div class="flex flex-wrap gap-4 text-sm sm:col-span-2">
                <label class="flex items-center gap-2"
                    ><input v-model="form.mode" type="radio" value="work" />{{ t('field_links.modes.work') }}</label
                >
                <label class="flex items-center gap-2"
                    ><input v-model="form.mode" type="radio" value="sign" />{{ t('field_links.modes.sign') }}</label
                >
            </div>
            <!-- Pick from the register of outside technicians -->
            <div v-if="form.mode === 'work' && pickable" class="grid gap-2 rounded-md border bg-background p-3 text-sm sm:col-span-2">
                <span class="flex items-center gap-2 font-medium"><UserSearch class="size-4" />{{ t('field_technicians.pick') }}</span>
                <Input v-model="pickSearch" type="search" :placeholder="t('field_technicians.pick_search')" />
                <p v-if="pickProvince" class="text-xs text-muted-foreground">
                    {{ t('field_technicians.pick_province', { province: pickProvince }) }}
                </p>
                <ul class="max-h-56 divide-y overflow-y-auto rounded-md border">
                    <li v-for="technician in technicians" :key="(technician.source ?? '') + technician.ulid">
                        <button
                            type="button"
                            class="flex w-full flex-wrap items-center gap-x-2 gap-y-0.5 px-3 py-2 text-left hover:bg-muted"
                            @click="pick(technician)"
                        >
                            <span class="font-medium">{{ technician.name }}</span>
                            <span v-if="technician.company" class="text-muted-foreground">· {{ technician.company }}</span>
                            <span class="text-muted-foreground">· {{ technician.phone }}</span>
                            <span
                                v-if="technician.in_province"
                                class="inline-flex items-center gap-0.5 rounded bg-green-100 px-1.5 text-xs text-green-900 dark:bg-green-950 dark:text-green-100"
                                ><MapPin class="size-3" />{{ t('field_technicians.in_province') }}</span
                            >
                            <span v-if="technician.source" class="text-xs text-muted-foreground">{{
                                t('field_technicians.from', { company: technician.source })
                            }}</span>
                            <span class="w-full truncate text-xs text-muted-foreground">{{ technician.provinces.join(', ') }}</span>
                        </button>
                    </li>
                    <li v-if="technicians.length === 0" class="px-3 py-3 text-center text-xs text-muted-foreground">
                        {{ t('field_technicians.pick_none') }}
                    </li>
                </ul>
            </div>
            <label class="grid gap-1 text-sm">
                <span
                    >{{ form.mode === 'work' ? t('field_links.technician_name') : t('field_links.customer_name') }}
                    <span class="text-red-600">*</span></span
                >
                <Input v-model="form.holder_name" required maxlength="255" />
                <InputError :message="form.errors.holder_name" />
            </label>
            <label class="grid gap-1 text-sm">
                <span>{{ t('field_links.company') }} <span class="text-red-600">*</span></span>
                <Input v-model="form.holder_company" required maxlength="255" />
                <InputError :message="form.errors.holder_company" />
            </label>
            <label class="grid gap-1 text-sm">
                <span>{{ t('field_links.phone') }} <span class="text-red-600">*</span></span>
                <Input v-model="form.holder_phone" type="tel" inputmode="tel" required maxlength="50" pattern="\+?[0-9][0-9\s\-()]{7,}[0-9]" />
                <InputError :message="form.errors.holder_phone" />
            </label>
            <label class="grid gap-1 text-sm">
                <span>{{ t('field_links.days') }}</span>
                <Input v-model="form.days" type="number" min="1" max="30" class="w-20" />
                <InputError :message="form.errors.days" />
            </label>
            <div class="sm:col-span-2">
                <Button :disabled="form.processing">{{ t('field_links.create') }}</Button>
            </div>
        </form>

        <ul v-if="links.length" class="divide-y rounded-md border text-sm">
            <li v-for="link in links" :key="link.id" class="space-y-1.5 px-3 py-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ link.holder_name }}</span>
                    <span v-if="link.holder_company" class="text-muted-foreground">· {{ link.holder_company }}</span>
                    <span class="rounded bg-muted px-1.5 text-xs">{{ t(`field_links.modes.${link.mode}`) }}</span>
                    <span
                        class="rounded px-1.5 text-xs"
                        :class="
                            state(link) === 'active'
                                ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200'
                                : 'bg-muted text-muted-foreground'
                        "
                        >{{ t(`field_links.states.${state(link)}`) }}</span
                    >
                    <span v-if="link.submitted_at" class="text-xs text-violet-700">{{
                        t('field_links.sent_at', { at: dateTime(link.submitted_at) })
                    }}</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ t('field_links.until', { at: dateTime(link.expires_at) }) }} · {{ t('field_links.by', { name: link.created_by_name ?? '-' }) }}
                    <template v-if="link.reviewed_at"> · {{ t('field_links.reviewed_by', { name: link.reviewed_by_name ?? '-' }) }}</template>
                </p>
                <div v-if="state(link) === 'active'" class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" @click="copy(link)"
                        ><Copy class="size-3.5" />{{ copied === link.id ? t('field_links.copied') : t('field_links.copy') }}</Button
                    >
                    <Button size="sm" variant="outline" as-child>
                        <a :href="line(link)" target="_blank" rel="noopener"><MessageCircle class="size-3.5" />{{ t('field_links.send_line') }}</a>
                    </Button>
                    <Button size="sm" variant="outline" as-child>
                        <a :href="link.url" target="_blank" rel="noopener"><ExternalLink class="size-3.5" />{{ t('field_links.open') }}</a>
                    </Button>
                    <Button size="sm" variant="ghost" class="text-red-600" @click="revoke(link)">{{ t('field_links.revoke') }}</Button>
                </div>
            </li>
        </ul>
    </section>
</template>
