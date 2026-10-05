<script setup lang="ts">
import PublicTicketCard, { type PublicTicket } from '@/components/PublicTicketCard.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { LoaderCircle, Search } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

// The public "track my repair" page: no sign-in, so no app layout or navigation.
// Not found — whether the company or the ticket — always reads the same.
const props = defineProps<{
    filters: { company: string | null; q: string };
    askCompany: boolean;
    searched: boolean;
    results: PublicTicket[];
    captcha: string | null;
    captchaFailed: boolean;
}>();

const form = reactive({ company: props.filters.company ?? '', q: props.filters.q, captcha: '' });
const loading = ref(false);
const submit = () =>
    router.get(
        route('service.track'),
        {
            ...(props.askCompany && form.company ? { company: form.company } : {}),
            q: form.q,
            ...(form.captcha ? { 'cf-turnstile-response': form.captcha } : {}),
        },
        {
            preserveState: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );

const inputClass =
    'h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-violet-400 focus:bg-white focus:ring-4 focus:ring-violet-100 dark:border-slate-700 dark:bg-slate-900';
</script>

<template>
    <Head :title="t('track.title')" />

    <div class="min-h-svh bg-gradient-to-b from-violet-50 to-white px-4 py-10 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-2xl space-y-6">
            <div class="space-y-3 text-center">
                <div
                    class="mx-auto flex size-16 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 text-white shadow-lg shadow-violet-500/30"
                >
                    <Search class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('track.title') }}</h1>
                <p class="text-sm text-muted-foreground">{{ t('track.intro') }}</p>
            </div>

            <form class="space-y-4 rounded-2xl border bg-white p-6 shadow-sm dark:bg-slate-950" @submit.prevent="submit">
                <div v-if="askCompany" class="space-y-1.5">
                    <label for="company" class="text-sm font-semibold">{{ t('track.company') }}</label>
                    <input
                        id="company"
                        v-model="form.company"
                        :class="inputClass"
                        :placeholder="t('track.company_placeholder')"
                        autocomplete="off"
                        required
                    />
                    <p class="text-xs text-muted-foreground">{{ t('track.company_hint') }}</p>
                </div>
                <div class="space-y-1.5">
                    <label for="q" class="text-sm font-semibold">{{ t('track.query') }}</label>
                    <div class="flex gap-2">
                        <input
                            id="q"
                            v-model="form.q"
                            :class="inputClass"
                            class="font-mono"
                            :placeholder="t('track.query_placeholder')"
                            autocomplete="off"
                            required
                            minlength="3"
                        />
                        <button
                            type="submit"
                            :disabled="loading"
                            class="flex h-12 shrink-0 items-center gap-2 rounded-xl bg-gradient-to-r from-violet-600 to-indigo-500 px-5 text-sm font-semibold text-white shadow-lg shadow-violet-500/30 transition hover:from-violet-700 hover:to-indigo-600 disabled:opacity-60"
                        >
                            <LoaderCircle v-if="loading" class="size-4 animate-spin" />
                            <Search v-else class="size-4" />
                            {{ t('track.search') }}
                        </button>
                    </div>
                </div>
                <TurnstileWidget v-if="captcha" v-model="form.captcha" :site-key="captcha" />
                <p v-if="captchaFailed" class="text-sm text-red-600">{{ t('track.captcha_failed') }}</p>
                <p class="rounded-xl bg-violet-50 px-4 py-3 text-sm text-violet-900 dark:bg-violet-950/50 dark:text-violet-100">
                    {{ t('track.hint') }}
                </p>
            </form>

            <!-- Results -->
            <template v-if="searched">
                <p
                    v-if="!results.length"
                    class="rounded-2xl border border-dashed bg-white p-6 text-center text-sm text-muted-foreground dark:bg-slate-950"
                >
                    {{ t('track.not_found') }}
                </p>
                <PublicTicketCard v-for="ticket in results" :key="ticket.ticket_no" :ticket="ticket" />
            </template>

            <div class="text-center">
                <Link :href="route('login')" class="text-sm text-violet-700 hover:underline dark:text-violet-300">{{
                    t('track.back_to_login')
                }}</Link>
            </div>
        </div>
    </div>
</template>
