<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/vue3';
import { Camera, Check, LogIn, Plus, QrCode, Send, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

// The public side of an asset's QR code: no sign-in, so no app layout. Only the code and a broad
// name of the device, and a form to report a problem; an unknown code or a wrong key reads the same.
const props = defineProps<{
    company: string | null;
    asset: { asset_code: string; name: string } | null;
    running: string | null;
    canReport: boolean;
    symptoms: string[];
    action: string | null;
    limits: { photos: number; photo_kb: number };
    captcha: string | null;
    signIn: string | null;
}>();

const form = useForm({
    symptoms: [] as string[],
    note: '',
    name: '',
    phone: '',
    email: '',
    photos: [] as File[],
    website: '', // never shown: robots fill it in
    'cf-turnstile-response': '',
});

const chips = ref([...props.symptoms]);
const typed = ref('');
const toggle = (label: string) => {
    const i = form.symptoms.indexOf(label);
    if (i >= 0) form.symptoms.splice(i, 1);
    else form.symptoms.push(label);
};
const addTyped = () => {
    const label = typed.value.trim();
    if (!label) return;
    if (!chips.value.includes(label)) chips.value.push(label);
    if (!form.symptoms.includes(label)) form.symptoms.push(label);
    typed.value = '';
};

// Photos are made small on the phone before they are sent.
const previews = ref<string[]>([]);
const shrink = (file: File, max = 1600): Promise<File> =>
    new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            const scale = Math.min(1, max / Math.max(img.width, img.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.width * scale);
            canvas.height = Math.round(img.height * scale);
            canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob((blob) => resolve(blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.8);
            URL.revokeObjectURL(img.src);
        };
        img.onerror = () => resolve(file);
        img.src = URL.createObjectURL(file);
    });
const addPhotos = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        if (form.photos.length >= props.limits.photos || !file.type.startsWith('image/')) continue;
        const small = await shrink(file);
        form.photos.push(small);
        previews.value.push(URL.createObjectURL(small));
    }
    input.value = '';
};
const removePhoto = (i: number) => {
    form.photos.splice(i, 1);
    URL.revokeObjectURL(previews.value[i]);
    previews.value.splice(i, 1);
};

const submit = () => props.action && form.post(props.action, { forceFormData: true, preserveScroll: true });

const input =
    'h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-base outline-none focus:border-violet-400 focus:ring-4 focus:ring-violet-100 dark:border-slate-700 dark:bg-slate-900';
const big = 'flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl px-4 text-base font-semibold transition active:scale-[0.98]';
</script>

<template>
    <Head :title="t('qr.public_title')" />

    <div class="min-h-svh bg-gradient-to-b from-violet-50 to-white px-4 py-8 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-md space-y-6">
            <div class="space-y-2 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 text-white">
                    <QrCode class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('qr.public_title') }}</h1>
                <p v-if="company" class="text-sm font-semibold text-violet-700 dark:text-violet-300">{{ company }}</p>
            </div>

            <div v-if="asset" class="rounded-2xl border bg-white p-5 text-center dark:bg-slate-950">
                <div class="text-lg font-semibold">{{ asset.name }}</div>
                <div class="font-mono text-sm text-muted-foreground">{{ asset.asset_code }}</div>
            </div>
            <p v-else class="rounded-2xl border border-dashed bg-white p-6 text-center text-sm text-muted-foreground dark:bg-slate-950">
                {{ t('qr.public_unknown') }}
            </p>

            <!-- A job already running for this device -->
            <div
                v-if="running"
                class="space-y-3 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-center dark:border-amber-800 dark:bg-amber-950"
            >
                <p class="font-semibold">{{ t('qr.running') }}</p>
                <a :href="running" :class="[big, 'bg-violet-600 text-white']">{{ t('qr.running_link') }}</a>
            </div>

            <!-- Report a problem -->
            <form v-else-if="canReport && asset" class="space-y-5 rounded-2xl border bg-white p-5 dark:bg-slate-950" @submit.prevent="submit">
                <div class="space-y-2">
                    <div class="font-semibold">{{ t('qr.symptoms') }} <span class="text-red-600">*</span></div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="label in chips"
                            :key="label"
                            type="button"
                            class="min-h-12 rounded-full border px-4 text-sm"
                            :class="form.symptoms.includes(label) ? 'border-violet-600 bg-violet-600 text-white' : 'bg-white dark:bg-slate-900'"
                            @click="toggle(label)"
                        >
                            <Check v-if="form.symptoms.includes(label)" class="mr-1 inline size-4" />{{ label }}
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <input
                            v-model="typed"
                            :class="input"
                            maxlength="100"
                            :placeholder="t('qr.symptom_other')"
                            @keydown.enter.prevent="addTyped"
                        />
                        <button type="button" class="h-12 shrink-0 rounded-xl border px-4" @click="addTyped"><Plus class="size-5" /></button>
                    </div>
                    <InputError :message="form.errors.symptoms" />
                    <textarea
                        v-model="form.note"
                        rows="2"
                        maxlength="1000"
                        class="w-full rounded-xl border px-4 py-2 text-base dark:bg-slate-900"
                        :placeholder="t('qr.note')"
                    />
                </div>

                <label class="block space-y-1.5">
                    <span class="font-semibold">{{ t('qr.name') }} <span class="text-red-600">*</span></span>
                    <input v-model="form.name" :class="input" maxlength="100" autocomplete="name" required />
                    <InputError :message="form.errors.name" />
                </label>
                <div class="space-y-1.5">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block space-y-1.5">
                            <span class="font-semibold">{{ t('qr.phone') }}</span>
                            <input v-model="form.phone" :class="input" type="tel" inputmode="tel" maxlength="30" autocomplete="tel" />
                        </label>
                        <label class="block space-y-1.5">
                            <span class="font-semibold">{{ t('qr.email') }}</span>
                            <input v-model="form.email" :class="input" type="email" maxlength="150" autocomplete="email" />
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('qr.contact_hint') }}</p>
                    <InputError :message="form.errors.phone || form.errors.email" />
                </div>

                <div class="space-y-2">
                    <div class="font-semibold">{{ t('qr.photos', { max: limits.photos }) }}</div>
                    <label v-if="form.photos.length < limits.photos" :class="[big, 'cursor-pointer border bg-white text-sm dark:bg-slate-900']">
                        <Camera class="size-5" />{{ t('qr.add_photo') }}
                        <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="addPhotos" />
                    </label>
                    <div class="grid grid-cols-3 gap-2">
                        <div v-for="(src, i) in previews" :key="src" class="relative">
                            <img :src="src" class="aspect-square w-full rounded-lg object-cover" alt="" />
                            <button type="button" class="absolute right-1 top-1 rounded-full bg-black/60 p-1 text-white" @click="removePhoto(i)">
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                    </div>
                    <InputError :message="form.errors.photos || (form.errors as Record<string, string>)['photos.0']" />
                </div>

                <!-- Never shown to people. -->
                <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true" />

                <TurnstileWidget v-if="captcha" v-model="form['cf-turnstile-response']" :site-key="captcha" />
                <InputError :message="(form.errors as Record<string, string>).captcha" />

                <button
                    type="submit"
                    :disabled="form.processing"
                    :class="[big, 'bg-gradient-to-r from-violet-600 to-indigo-500 text-white shadow-lg disabled:opacity-60']"
                >
                    <Send class="size-5" />{{ t('qr.submit') }}
                </button>
                <p class="text-xs text-muted-foreground">{{ t('qr.privacy') }}</p>
            </form>

            <a
                v-if="signIn"
                :href="signIn"
                class="flex min-h-12 items-center justify-center gap-2 text-sm text-muted-foreground hover:text-violet-700"
            >
                <LogIn class="size-4" />{{ t('qr.staff_sign_in') }}
            </a>
        </div>
    </div>
</template>
