<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import AuthBrandLayout from '@/layouts/auth/AuthBrandLayout.vue';
import { t } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CircleCheck, Eye, EyeOff, LoaderCircle, Lock, LogIn, Mail, Search, ShieldCheck } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const trust = ['auth.trust.secure', 'auth.trust.isolated', 'auth.trust.sla', 'auth.trust.audit'];

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBrandLayout>
        <Head :title="t('auth.login_title')" />

        <div class="w-full max-w-md rounded-3xl bg-white p-8 shadow-xl shadow-slate-200/70 ring-1 ring-slate-100 sm:p-10">
            <div class="flex flex-col items-center text-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-violet-50 text-violet-600">
                    <ShieldCheck class="h-7 w-7" />
                </div>
                <h1 class="mt-5 text-2xl font-bold text-slate-900">{{ t('auth.login_title') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ t('auth.login_subtitle') }}</p>
            </div>

            <div v-if="status" class="mt-6 rounded-lg bg-green-50 px-4 py-3 text-center text-sm font-medium text-green-700">
                {{ status }}
            </div>

            <form @submit.prevent="submit" class="mt-8 space-y-5">
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700">{{ t('auth.email') }}</label>
                    <div class="relative">
                        <Mail class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autofocus
                            autocomplete="email"
                            placeholder="email@example.com"
                            class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm text-slate-900 placeholder:text-slate-400 focus:border-violet-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-violet-100"
                        />
                    </div>
                    <InputError :message="form.errors.email" class="mt-1.5" />
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700">{{ t('auth.password') }}</label>
                    <div class="relative">
                        <Lock class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="current-password"
                            class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-11 text-sm text-slate-900 focus:border-violet-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-violet-100"
                        />
                        <button
                            type="button"
                            class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 hover:text-slate-600"
                            :aria-label="showPassword ? t('auth.hide_password') : t('auth.show_password')"
                            @click="showPassword = !showPassword"
                        >
                            <EyeOff v-if="showPassword" class="h-4 w-4" />
                            <Eye v-else class="h-4 w-4" />
                        </button>
                    </div>
                    <InputError :message="form.errors.password" class="mt-1.5" />
                </div>

                <div class="flex items-center justify-between">
                    <label for="remember" class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                        <Checkbox id="remember" v-model:checked="form.remember" />
                        {{ t('auth.remember_device') }}
                    </label>
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-sm font-semibold text-violet-600 hover:text-violet-700"
                    >
                        {{ t('auth.forgot') }}
                    </Link>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-violet-600 to-indigo-500 text-sm font-semibold text-white shadow-lg shadow-violet-500/30 transition hover:from-violet-700 hover:to-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    <LogIn v-else class="h-4 w-4" />
                    {{ t('auth.login_button') }}
                </button>
            </form>

            <!-- Track my repair: for customers, no sign-in -->
            <Link
                :href="route('service.track')"
                class="mt-4 flex items-center justify-center gap-2 text-sm font-semibold text-slate-600 hover:text-violet-700"
            >
                <Search class="h-4 w-4" />
                {{ t('track.link') }}
            </Link>

            <div class="mt-8 grid grid-cols-2 gap-x-4 gap-y-2 border-t border-slate-100 pt-6">
                <div v-for="item in trust" :key="item" class="flex items-center gap-1.5 text-xs text-slate-500">
                    <CircleCheck class="h-3.5 w-3.5 shrink-0 text-violet-500" />
                    {{ t(item) }}
                </div>
            </div>
        </div>
    </AuthBrandLayout>
</template>
