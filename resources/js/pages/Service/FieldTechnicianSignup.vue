<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Check, CircleCheck, HardHat, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * The sign-up page outside technicians around the country open from the link the company sends
 * them (no account): who they are, how to reach them, the provinces they work in and the kinds of
 * work they take, and their consent. They then wait for staff to approve them.
 */
const props = defineProps<{
    company: string | null;
    provinces: string[];
    skills: string[];
    maxProvinces: number;
    captcha: string | null;
}>();

const shared = usePage<SharedData>();
const errors = computed(() => shared.props.errors as Record<string, string>);
const done = computed(() => !!shared.props.flash.success);

const form = useForm({
    name: '',
    company: '',
    phone: '',
    line_id: '',
    email: '',
    note: '',
    provinces: [] as string[],
    skills: [] as string[],
    consent: false,
    'cf-turnstile-response': '',
});

// The provinces: typed to narrow the list, ticked as chips.
const provinceSearch = ref('');
const shownProvinces = computed(() => {
    const term = provinceSearch.value.trim();
    return props.provinces.filter((p) => !form.provinces.includes(p) && (term === '' || p.includes(term)));
});
const addProvince = (province: string) => {
    if (form.provinces.length < props.maxProvinces && !form.provinces.includes(province)) form.provinces.push(province);
    provinceSearch.value = '';
};
const removeProvince = (province: string) => (form.provinces = form.provinces.filter((p) => p !== province));
const toggleSkill = (skill: string) => (form.skills = form.skills.includes(skill) ? form.skills.filter((s) => s !== skill) : [...form.skills, skill]);

const submit = () => form.post(window.location.pathname, { preserveScroll: true, onSuccess: () => form.reset() });
const again = () => window.location.reload();

const input =
    'h-12 w-full rounded-xl border border-input bg-background px-4 text-base outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-200 dark:focus:ring-violet-900';
const chip = 'inline-flex min-h-10 items-center gap-1.5 rounded-full border px-4 text-sm';
</script>

<template>
    <Head :title="t('technician_signup.title')" />

    <div class="min-h-svh bg-gradient-to-b from-violet-50 to-white px-4 py-6 pb-16 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-2xl space-y-5">
            <div class="flex items-center gap-3">
                <div class="flex size-12 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 text-white shadow">
                    <HardHat class="size-6" />
                </div>
                <div>
                    <h1 class="text-xl font-semibold">{{ t('technician_signup.title') }}</h1>
                    <p v-if="company" class="text-sm text-violet-700 dark:text-violet-300">{{ company }}</p>
                </div>
            </div>

            <div v-if="!company" class="rounded-2xl border bg-background p-6 text-center text-muted-foreground">
                {{ t('technician_signup.not_found') }}
            </div>

            <div v-else-if="done" class="space-y-4 rounded-2xl border bg-background p-6 text-center">
                <CircleCheck class="mx-auto size-14 text-green-600" />
                <h2 class="text-lg font-semibold">{{ t('technician_signup.done_title') }}</h2>
                <p class="text-muted-foreground">{{ t('technician_signup.done') }}</p>
                <button type="button" class="text-sm text-violet-700 underline dark:text-violet-300" @click="again">
                    {{ t('technician_signup.again') }}
                </button>
            </div>

            <form v-else class="space-y-5" @submit.prevent="submit">
                <p class="rounded-2xl bg-violet-50 p-4 text-sm text-violet-900 dark:bg-violet-950/50 dark:text-violet-100">
                    {{ t('technician_signup.intro', { company }) }}
                </p>

                <section class="space-y-4 rounded-2xl border bg-background p-5">
                    <h2 class="font-semibold">{{ t('technician_signup.about_you') }}</h2>
                    <label class="grid gap-1.5 text-sm">
                        <span>{{ t('technician_signup.name') }} <span class="text-red-600">*</span></span>
                        <input v-model="form.name" :class="input" required maxlength="255" autocomplete="name" />
                        <InputError :message="errors.name" />
                    </label>
                    <label class="grid gap-1.5 text-sm">
                        <span>{{ t('technician_signup.company') }}</span>
                        <input v-model="form.company" :class="input" maxlength="255" autocomplete="organization" />
                        <InputError :message="errors.company" />
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1.5 text-sm">
                            <span>{{ t('technician_signup.phone') }} <span class="text-red-600">*</span></span>
                            <input v-model="form.phone" :class="input" type="tel" inputmode="tel" required maxlength="50" autocomplete="tel" />
                            <InputError :message="errors.phone" />
                        </label>
                        <label class="grid gap-1.5 text-sm">
                            <span>{{ t('technician_signup.line_id') }}</span>
                            <input v-model="form.line_id" :class="input" maxlength="100" autocapitalize="none" />
                            <InputError :message="errors.line_id" />
                        </label>
                    </div>
                    <label class="grid gap-1.5 text-sm">
                        <span>{{ t('technician_signup.email') }}</span>
                        <input v-model="form.email" :class="input" type="email" maxlength="255" autocomplete="email" />
                        <InputError :message="errors.email" />
                    </label>
                </section>

                <section class="space-y-3 rounded-2xl border bg-background p-5">
                    <div>
                        <h2 class="font-semibold">{{ t('technician_signup.provinces') }} <span class="text-red-600">*</span></h2>
                        <p class="text-xs text-muted-foreground">{{ t('technician_signup.provinces_hint', { max: maxProvinces }) }}</p>
                    </div>
                    <div v-if="form.provinces.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="province in form.provinces"
                            :key="province"
                            type="button"
                            :class="[chip, 'border-violet-500 bg-violet-600 text-white']"
                            @click="removeProvince(province)"
                        >
                            {{ province }}<X class="size-4" />
                        </button>
                    </div>
                    <input v-model="provinceSearch" :class="input" :placeholder="t('technician_signup.province_search')" />
                    <div class="flex max-h-56 flex-wrap gap-2 overflow-y-auto">
                        <button
                            v-for="province in shownProvinces"
                            :key="province"
                            type="button"
                            :class="[chip, 'bg-background']"
                            @click="addProvince(province)"
                        >
                            {{ province }}
                        </button>
                    </div>
                    <InputError :message="errors.provinces || errors['provinces.0']" />
                </section>

                <section class="space-y-3 rounded-2xl border bg-background p-5">
                    <div>
                        <h2 class="font-semibold">{{ t('technician_signup.skills') }} <span class="text-red-600">*</span></h2>
                        <p class="text-xs text-muted-foreground">{{ t('technician_signup.skills_hint') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="skill in skills"
                            :key="skill"
                            type="button"
                            :class="[chip, form.skills.includes(skill) ? 'border-violet-500 bg-violet-600 text-white' : 'bg-background']"
                            @click="toggleSkill(skill)"
                        >
                            <Check v-if="form.skills.includes(skill)" class="size-4" />{{ t(`field_technicians.skills.${skill}`) }}
                        </button>
                    </div>
                    <InputError :message="errors.skills" />
                    <label class="grid gap-1.5 pt-2 text-sm">
                        <span>{{ t('technician_signup.note') }}</span>
                        <textarea
                            v-model="form.note"
                            rows="3"
                            maxlength="2000"
                            class="w-full rounded-xl border border-input bg-background px-4 py-3 text-base outline-none focus:border-violet-500"
                            :placeholder="t('technician_signup.note_placeholder')"
                        />
                    </label>
                </section>

                <label class="flex items-start gap-3 rounded-2xl border bg-background p-5 text-sm">
                    <input v-model="form.consent" type="checkbox" class="mt-0.5 size-5 shrink-0" required />
                    <span>{{ t('technician_signup.consent', { company }) }}</span>
                </label>
                <InputError :message="errors.consent" />

                <TurnstileWidget v-if="captcha" v-model="form['cf-turnstile-response']" :site-key="captcha" />
                <InputError :message="errors.captcha" />

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-violet-600 to-indigo-500 text-base font-semibold text-white shadow-lg disabled:opacity-60"
                >
                    <Check class="size-5" />{{ t('technician_signup.submit') }}
                </button>
            </form>
        </div>
    </div>
</template>
