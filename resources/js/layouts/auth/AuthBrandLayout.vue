<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CalendarCheck, ClipboardList, FileText, Server, ShieldCheck, Wrench } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const appName = computed(() => page.props.name);

const chips = computed(() => {
    const auth = (page.props.translations as Record<string, any> | undefined)?.auth;

    return Array.isArray(auth?.chips) ? (auth.chips as string[]) : [];
});

const features = [
    { icon: Server, title: 'auth.features.asset_title', text: 'auth.features.asset_text' },
    { icon: ClipboardList, title: 'auth.features.service_title', text: 'auth.features.service_text' },
    { icon: FileText, title: 'auth.features.contract_title', text: 'auth.features.contract_text' },
    { icon: CalendarCheck, title: 'auth.features.maintenance_title', text: 'auth.features.maintenance_text' },
];
</script>

<template>
    <div class="flex min-h-svh bg-slate-50">
        <!-- Brand panel (hidden on small screens) -->
        <aside
            class="relative hidden w-[45%] flex-col justify-between overflow-hidden bg-gradient-to-br from-[#1b1446] via-[#2a2170] to-[#3b2f94] p-10 text-white lg:flex xl:p-12"
        >
            <!-- Decoration -->
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div
                    class="absolute inset-0 opacity-[0.07] [background-image:linear-gradient(to_right,white_1px,transparent_1px),linear-gradient(to_bottom,white_1px,transparent_1px)] [background-size:48px_48px]"
                />
                <div class="absolute -right-24 top-10 h-96 w-96 rounded-full bg-violet-500/25 blur-3xl" />
                <div class="absolute -bottom-32 -left-20 h-96 w-96 rounded-full bg-indigo-400/20 blur-3xl" />
                <div class="absolute right-16 top-24 flex h-64 w-64 items-center justify-center rounded-full border border-white/10">
                    <div class="flex h-48 w-48 items-center justify-center rounded-full border border-white/10">
                        <div class="flex h-32 w-32 items-center justify-center rounded-full border border-violet-300/20 bg-violet-400/5">
                            <Wrench class="h-16 w-16 text-violet-200/40" :stroke-width="1.25" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-500 shadow-lg shadow-violet-900/40">
                        <ShieldCheck class="h-6 w-6" />
                    </div>
                    <div class="leading-tight">
                        <div class="text-lg font-bold">{{ appName }}</div>
                        <div class="text-[11px] font-semibold tracking-[0.15em] text-violet-300">{{ t('auth.brand_tagline') }}</div>
                    </div>
                </div>

                <h2 class="mt-14 text-4xl font-bold leading-snug">
                    {{ t('auth.hero_line1') }}<br />
                    <span class="text-violet-300">{{ t('auth.hero_line2') }}</span
                    ><br />
                    {{ t('auth.hero_line3') }}
                </h2>
                <div class="mt-6 h-1 w-12 rounded-full bg-violet-400" />
                <p class="mt-6 max-w-md text-sm leading-relaxed text-indigo-100/80">{{ t('auth.hero_description') }}</p>
            </div>

            <div class="relative">
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="chip in chips"
                        :key="chip"
                        class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs text-indigo-100"
                    >
                        <ShieldCheck class="h-3 w-3 text-violet-300" />
                        {{ chip }}
                    </span>
                </div>

                <div class="mt-6 grid grid-cols-2 gap-3">
                    <div v-for="feature in features" :key="feature.title" class="rounded-xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                        <div class="flex items-center gap-2 text-sm font-semibold">
                            <component :is="feature.icon" class="h-4 w-4 text-violet-300" />
                            {{ t(feature.title) }}
                        </div>
                        <p class="mt-1 text-xs text-indigo-100/70">{{ t(feature.text) }}</p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Form panel -->
        <main class="flex flex-1 flex-col">
            <div class="flex flex-1 items-center justify-center px-4 py-10 sm:px-6">
                <slot />
            </div>
            <footer class="pb-6 text-center text-xs text-slate-400">
                {{ t('auth.copyright', { year: new Date().getFullYear() }) }}
            </footer>
        </main>
    </div>
</template>
