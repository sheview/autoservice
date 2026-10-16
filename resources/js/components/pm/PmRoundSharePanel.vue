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

export interface PmRoundShare {
    id: number;
    recipient_name: string | null;
    recipient_email: string | null;
    allow_download: boolean;
    view_count: number;
    created_by_name: string | null;
    revoked_by_name: string | null;
    from: string;
    to: string;
    active: boolean;
    expires_at: string;
    revoked_at: string | null;
    last_viewed_at: string | null;
    created_at: string;
}

/**
 * Customers' links to the round report of one customer and period (PM Reports page): make one
 * (shown once, to copy), see who opened which, revoke. Each link is secret and lasts only so many days.
 */
const props = defineProps<{ customerId: number; from: string; to: string; shares: PmRoundShare[]; shareDays: number; maxShareDays: number }>();

const page = usePage<SharedData>();
const newLink = computed(() => (page.props.flash as { share_url?: string | null }).share_url ?? null);

const open = ref(false);
const form = useForm({
    recipient_name: '',
    recipient_email: '',
    days: props.shareDays,
    allow_download: true as boolean,
});
const create = () =>
    form
        .transform((data) => ({ ...data, customer_id: props.customerId, from: props.from, to: props.to }))
        .post(route('maintenance.round-shares.store'), {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                form.reset();
            },
        });
const revoke = (share: PmRoundShare) =>
    confirm(t('pm_shares.confirm_revoke')) && router.delete(route('maintenance.round-shares.destroy', share.id), { preserveScroll: true });

const copied = ref(false);
const copy = async () => {
    if (!newLink.value) return;
    await navigator.clipboard.writeText(newLink.value);
    copied.value = true;
};

const state = (share: PmRoundShare) => (share.revoked_at ? 'revoked' : share.active ? 'active' : 'expired');
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
                <h3 class="flex items-center gap-2 font-semibold"><Link2 class="h-4 w-4" />{{ t('pm_round_shares.title') }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('pm_round_shares.hint') }}</p>
            </div>
            <Button v-if="!open" size="sm" @click="open = true">{{ t('pm_round_shares.share') }}</Button>
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
            <p class="text-sm font-medium sm:col-span-2">{{ t('pm_round_shares.period', { from, to }) }}</p>
            <div class="grid gap-1">
                <Label for="round_recipient_name">{{ t('pm_shares.recipient_name') }}</Label>
                <Input id="round_recipient_name" v-model="form.recipient_name" />
                <InputError :message="form.errors.recipient_name" />
            </div>
            <div class="grid gap-1">
                <Label for="round_recipient_email">{{ t('pm_shares.recipient_email') }}</Label>
                <Input id="round_recipient_email" v-model="form.recipient_email" type="email" />
                <InputError :message="form.errors.recipient_email" />
            </div>
            <div class="grid gap-1">
                <Label for="round_days">{{ t('pm_shares.days') }}</Label>
                <Input id="round_days" v-model="form.days" type="number" min="1" :max="maxShareDays" required />
                <InputError :message="form.errors.days" />
            </div>
            <div class="grid content-end gap-2 text-sm">
                <label class="flex items-center gap-2"
                    ><input v-model="form.allow_download" type="checkbox" class="size-4" />{{ t('pm_shares.allow_download') }}</label
                >
            </div>
            <InputError
                :message="(form.errors as Record<string, string>).share ?? (form.errors as Record<string, string>).to"
                class="sm:col-span-2"
            />
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
                        {{ t('pm_round_shares.period', { from: share.from, to: share.to }) }} ·
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
                <Button v-if="share.active" size="sm" variant="outline" class="text-red-700 dark:text-red-400" @click="revoke(share)">{{
                    t('pm_shares.revoke')
                }}</Button>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">{{ t('pm_shares.none') }}</p>
    </section>
</template>
