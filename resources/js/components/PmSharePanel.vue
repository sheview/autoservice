<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { CircleCheck, Copy, Link2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

export interface PmShare {
    id: number;
    recipient_name: string | null;
    recipient_email: string | null;
    allow_download: boolean;
    view_count: number;
    created_by_name: string | null;
    revoked_by_name: string | null;
    version: number | null;
    active: boolean;
    expires_at: string;
    revoked_at: string | null;
    last_viewed_at: string | null;
    created_at: string;
}

/**
 * Customers' links to a published PM report: make one (shown once, to copy), see who opened
 * which, revoke. Each link is secret and lasts only so many days.
 */
const props = defineProps<{ visitUlid: string; shares: PmShare[]; canShare: boolean; shareDays: number; maxShareDays: number }>();

const page = usePage<SharedData>();
const newLink = computed(() => (page.props.flash as { share_url?: string | null }).share_url ?? null);

const open = ref(false);
const form = useForm({
    recipient_name: '',
    recipient_email: '',
    days: props.shareDays,
    allow_download: true as boolean,
    follow_latest: false as boolean,
});
const create = () =>
    form.post(route('maintenance.visits.shares.store', props.visitUlid), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
const revoke = (share: PmShare) =>
    confirm(t('pm_shares.confirm_revoke')) &&
    router.delete(route('maintenance.visits.shares.destroy', [props.visitUlid, share.id]), { preserveScroll: true });

const copied = ref(false);
const copy = async () => {
    if (!newLink.value) return;
    await navigator.clipboard.writeText(newLink.value);
    copied.value = true;
};

const state = (share: PmShare) => (share.revoked_at ? 'revoked' : share.active ? 'active' : 'expired');
const stateClass: Record<string, string> = {
    active: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    expired: 'bg-muted text-muted-foreground',
    revoked: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};
</script>

<template>
    <section class="space-y-3 rounded-md border p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="flex items-center gap-2 font-semibold"><Link2 class="h-4 w-4" />{{ t('pm_shares.title') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('pm_shares.hint') }}</p>
            </div>
            <Button v-if="canShare && !open" size="sm" @click="open = true">{{ t('pm_shares.create') }}</Button>
        </div>

        <!-- The new link: only now -->
        <div v-if="newLink" class="space-y-2 rounded-md border border-green-300 bg-green-50 p-3 text-sm dark:border-green-800 dark:bg-green-950">
            <p class="font-semibold">{{ t('pm_shares.copy_now') }}</p>
            <div class="flex gap-2">
                <Input :model-value="newLink" readonly class="font-mono text-xs" @focus="($event.target as HTMLInputElement).select()" />
                <Button size="sm" variant="outline" @click="copy">
                    <component :is="copied ? CircleCheck : Copy" class="h-4 w-4" />{{ copied ? t('pm_shares.copied') : t('pm_shares.copy') }}
                </Button>
            </div>
        </div>

        <form v-if="open" class="grid gap-3 rounded-md border p-3 sm:grid-cols-2" @submit.prevent="create">
            <div class="grid gap-1">
                <Label for="recipient_name">{{ t('pm_shares.recipient_name') }}</Label>
                <Input id="recipient_name" v-model="form.recipient_name" />
                <InputError :message="form.errors.recipient_name" />
            </div>
            <div class="grid gap-1">
                <Label for="recipient_email">{{ t('pm_shares.recipient_email') }}</Label>
                <Input id="recipient_email" v-model="form.recipient_email" type="email" />
                <InputError :message="form.errors.recipient_email" />
            </div>
            <div class="grid gap-1">
                <Label for="days">{{ t('pm_shares.days') }}</Label>
                <Input id="days" v-model="form.days" type="number" min="1" :max="maxShareDays" required />
                <InputError :message="form.errors.days" />
            </div>
            <div class="grid content-end gap-2 text-sm">
                <label class="flex items-center gap-2"
                    ><input v-model="form.allow_download" type="checkbox" class="size-4" />{{ t('pm_shares.allow_download') }}</label
                >
                <label class="flex items-center gap-2"
                    ><input v-model="form.follow_latest" type="checkbox" class="size-4" />{{ t('pm_shares.follow_latest') }}</label
                >
            </div>
            <InputError :message="(form.errors as Record<string, string>).share" class="sm:col-span-2" />
            <div class="flex gap-2 sm:col-span-2">
                <Button size="sm" :disabled="form.processing">{{ t('pm_shares.create') }}</Button>
                <Button size="sm" type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
            </div>
        </form>

        <ul v-if="shares.length" class="divide-y rounded-md border text-sm">
            <li v-for="share in shares" :key="share.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ share.recipient_name ?? t('pm_shares.no_recipient') }}</span>
                        <span v-if="share.recipient_email" class="text-xs text-muted-foreground">{{ share.recipient_email }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs" :class="stateClass[state(share)]">{{
                            t(`pm_shares.states.${state(share)}`)
                        }}</span>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ share.version ? t('pm_visits.version', { version: share.version }) : t('pm_shares.latest') }} ·
                        {{ t('pm_shares.expires', { at: dateTime(share.expires_at) }) }} · {{ t('pm_shares.views', { count: share.view_count })
                        }}<template v-if="share.last_viewed_at"> · {{ t('pm_shares.last_viewed', { at: dateTime(share.last_viewed_at) }) }}</template>
                        · {{ share.allow_download ? t('pm_shares.download_on') : t('pm_shares.download_off') }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ t('pm_shares.made_by', { name: share.created_by_name ?? '-', at: dateTime(share.created_at) }) }}
                        <template v-if="share.revoked_at">
                            · {{ t('pm_shares.revoked_by', { name: share.revoked_by_name ?? '-', at: dateTime(share.revoked_at) }) }}</template
                        >
                    </div>
                </div>
                <Button v-if="canShare && share.active" size="sm" variant="outline" class="text-red-700 dark:text-red-400" @click="revoke(share)">{{
                    t('pm_shares.revoke')
                }}</Button>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">{{ t('pm_shares.none') }}</p>
    </section>
</template>
