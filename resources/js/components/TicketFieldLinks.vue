<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';
import { Copy, ExternalLink, Link2, MessageCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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
</script>

<template>
    <section class="space-y-3 rounded-md border p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 text-sm font-semibold"><Link2 class="size-4" />{{ t('field_links.title') }}</h3>
            <Button size="sm" variant="outline" @click="open = !open">{{ t('field_links.new') }}</Button>
        </div>
        <p class="text-xs text-muted-foreground">{{ t('field_links.hint') }}</p>

        <div v-if="waiting.length" class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
            <span>{{ t('field_links.waiting', { names: waiting.map((l) => l.holder_name).join(', ') }) }}</span>
            <Button size="sm" @click="review">{{ t('field_links.mark_reviewed') }}</Button>
        </div>

        <form v-if="open" class="grid gap-3 rounded-md bg-muted/40 p-3 sm:grid-cols-2" @submit.prevent="create">
            <div class="flex flex-wrap gap-4 text-sm sm:col-span-2">
                <label class="flex items-center gap-2"><input v-model="form.mode" type="radio" value="work" />{{ t('field_links.modes.work') }}</label>
                <label class="flex items-center gap-2"><input v-model="form.mode" type="radio" value="sign" />{{ t('field_links.modes.sign') }}</label>
            </div>
            <Input v-model="form.holder_name" required maxlength="255" :placeholder="form.mode === 'work' ? t('field_links.technician_name') : t('field_links.customer_name')" />
            <Input v-model="form.holder_company" maxlength="255" :placeholder="t('field_links.company')" />
            <Input v-model="form.holder_phone" maxlength="50" :placeholder="t('field_links.phone')" />
            <label class="flex items-center gap-2 text-sm">
                {{ t('field_links.days') }}
                <Input v-model="form.days" type="number" min="1" max="30" class="w-20" />
            </label>
            <InputError class="sm:col-span-2" :message="form.errors.holder_name || form.errors.days" />
            <div class="sm:col-span-2"><Button :disabled="form.processing">{{ t('field_links.create') }}</Button></div>
        </form>

        <ul v-if="links.length" class="divide-y rounded-md border text-sm">
            <li v-for="link in links" :key="link.id" class="space-y-1.5 px-3 py-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ link.holder_name }}</span>
                    <span v-if="link.holder_company" class="text-muted-foreground">· {{ link.holder_company }}</span>
                    <span class="rounded bg-muted px-1.5 text-xs">{{ t(`field_links.modes.${link.mode}`) }}</span>
                    <span
                        class="rounded px-1.5 text-xs"
                        :class="state(link) === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200' : 'bg-muted text-muted-foreground'"
                        >{{ t(`field_links.states.${state(link)}`) }}</span
                    >
                    <span v-if="link.submitted_at" class="text-xs text-violet-700">{{ t('field_links.sent_at', { at: dateTime(link.submitted_at) }) }}</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ t('field_links.until', { at: dateTime(link.expires_at) }) }} · {{ t('field_links.by', { name: link.created_by_name ?? '-' }) }}
                    <template v-if="link.reviewed_at"> · {{ t('field_links.reviewed_by', { name: link.reviewed_by_name ?? '-' }) }}</template>
                </p>
                <div v-if="state(link) === 'active'" class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" @click="copy(link)"><Copy class="size-3.5" />{{ copied === link.id ? t('field_links.copied') : t('field_links.copy') }}</Button>
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
