<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StepProgress from '@/components/StepProgress.vue';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { LoaderCircle, Search, Wrench } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Tracked {
    ticket_no: string;
    title: string;
    device: string | null;
    status: string;
    step: number;
    state: 'active' | 'done' | 'paused' | 'cancelled';
    at: Record<string, string | null>;
}

// The public "track my repair" page: no sign-in, so no app layout or navigation.
const props = defineProps<{
    filters: { company: string | null; q: string };
    askCompany: boolean;
    company: string | null;
    companyUnknown: boolean;
    searched: boolean;
    results: Tracked[];
}>();

const form = reactive({ company: props.filters.company ?? '', q: props.filters.q });
const loading = ref(false);
const submit = () =>
    router.get(
        route('service.track'),
        { ...(props.askCompany ? { company: form.company } : {}), q: form.q },
        {
            preserveState: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );

const steps = ['new', 'assigned', 'in_progress', 'resolved', 'closed'];
const stepsOf = (ticket: Tracked) =>
    steps.map((key) => ({
        key,
        label: t(`track.steps.${key}`),
        at: ticket.at[key]
            ? new Date(ticket.at[key]!).toLocaleString('th-TH-u-ca-gregory', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
            : null,
    }));
const companyError = computed(() => (props.companyUnknown ? t('track.company_unknown') : undefined));
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
                <p v-if="company" class="text-sm font-semibold text-violet-700 dark:text-violet-300">{{ company }}</p>
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
                    <InputError :message="companyError" />
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
                    {{ t('track.not_found', { q: filters.q }) }}
                </p>
                <template v-else>
                    <p class="text-sm text-muted-foreground">{{ t('track.found', { count: results.length }) }}</p>
                    <article
                        v-for="ticket in results"
                        :key="ticket.ticket_no"
                        class="space-y-4 rounded-2xl border bg-white p-6 shadow-sm dark:bg-slate-950"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300"
                            >
                                <Wrench class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="font-mono text-sm text-muted-foreground">{{ ticket.ticket_no }}</div>
                                <div class="font-semibold">{{ ticket.title }}</div>
                                <div v-if="ticket.device" class="text-sm text-muted-foreground">{{ t('track.device') }}: {{ ticket.device }}</div>
                            </div>
                        </div>
                        <StepProgress :steps="stepsOf(ticket)" :current="ticket.step" :state="ticket.state" />
                        <p
                            v-if="ticket.state === 'paused' || ticket.state === 'cancelled'"
                            class="text-sm"
                            :class="ticket.state === 'cancelled' ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400'"
                        >
                            {{ t(`track.states.${ticket.state}`) }}
                        </p>
                    </article>
                </template>
            </template>

            <div class="text-center">
                <Link :href="route('login')" class="text-sm text-violet-700 hover:underline dark:text-violet-300">{{
                    t('track.back_to_login')
                }}</Link>
            </div>
        </div>
    </div>
</template>
