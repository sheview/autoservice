<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/vue3';
import { Camera, Check, LogIn, Plus, QrCode, Send, Trash2, Wrench } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

// The public side of an asset's QR code: no sign-in, so no app layout. What the device is first
// (PublicAssetLabel: never where it is or who uses it), then "report a problem" at the end, which
// opens the form; an unknown code or a wrong key reads the same.
const props = defineProps<{
    company: string | null;
    asset: {
        asset_code: string;
        name: string;
        category: string | null;
        brand: string | null;
        model: string | null;
        serials: string[];
        status: string;
        warranty_expires_at: string | null;
        warranty: 'none' | 'active' | 'expiring' | 'expired';
        photo: string | null;
    } | null;
    // The MA contract: covering today, else the latest one (null: none, or the module is off).
    contract: { contract_no: string; starts_on: string; ends_on: string; phase: 'upcoming' | 'active' | 'expiring' | 'expired' } | null;
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

// Who reported last time on this phone: filled in again, so the next report is only the symptoms.
const CONTACT_KEY = 'qr-report-contact';
const savedContact = (() => {
    try {
        const saved = JSON.parse(localStorage.getItem(CONTACT_KEY) ?? 'null');
        return saved && typeof saved.name === 'string' && (saved.phone || saved.email) ? saved : null;
    } catch {
        return null;
    }
})();
if (savedContact) {
    form.name = savedContact.name;
    form.phone = savedContact.phone ?? '';
    form.email = savedContact.email ?? '';
}
// The fields are shown when nothing is saved, when changing it, or when the server found a problem in them.
const editingContact = ref(!savedContact);
const contactOpen = computed(() => editingContact.value || !!(form.errors.name || form.errors.phone || form.errors.email));

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

const submit = () => {
    if (!props.action) return;
    try {
        localStorage.setItem(CONTACT_KEY, JSON.stringify({ name: form.name.trim(), phone: form.phone.trim(), email: form.email.trim() }));
    } catch {
        // private mode / storage off: asked again next time
    }
    form.post(props.action, { forceFormData: true, preserveScroll: true });
};

// The form opens from the button at the end of the page.
const reporting = ref(false);
const formEl = ref<HTMLFormElement | null>(null);
const startReport = async () => {
    reporting.value = true;
    await nextTick();
    formEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

// A document for people outside: the Buddhist year (th-TH).
const date = (iso: string | null) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('th-TH', { dateStyle: 'long' }) : '');
const warrantyClass: Record<string, string> = {
    none: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    active: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    expiring: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    expired: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};
const contractClass: Record<string, string> = { ...warrantyClass, upcoming: warrantyClass.none };

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

            <!-- The device -->
            <section v-if="asset" class="space-y-4 rounded-2xl border bg-white p-5 dark:bg-slate-950">
                <img v-if="asset.photo" :src="asset.photo" :alt="asset.name" class="aspect-[4/3] w-full rounded-xl border object-cover" />
                <div>
                    <div class="font-mono text-sm text-muted-foreground">{{ asset.asset_code }}</div>
                    <h2 class="text-xl font-semibold">{{ asset.name }}</h2>
                    <div class="text-sm text-muted-foreground">{{ [asset.category, asset.brand, asset.model].filter(Boolean).join(' · ') }}</div>
                </div>
                <div class="rounded-xl px-3 py-2 text-sm font-semibold" :class="warrantyClass[asset.warranty]">
                    {{ t(`qr.warranty_states.${asset.warranty}`, { date: date(asset.warranty_expires_at) }) }}
                </div>
                <div class="rounded-xl px-3 py-2 text-sm font-semibold" :class="contractClass[contract?.phase ?? 'none']">
                    {{
                        t(`qr.contract_states.${contract?.phase ?? 'none'}`, {
                            no: contract?.contract_no ?? '',
                            from: date(contract?.starts_on ?? null),
                            to: date(contract?.ends_on ?? null),
                        })
                    }}
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                    <template v-if="asset.brand">
                        <dt class="text-muted-foreground">{{ t('assets.brand') }}</dt>
                        <dd>{{ asset.brand }}</dd>
                    </template>
                    <template v-if="asset.model">
                        <dt class="text-muted-foreground">{{ t('assets.model') }}</dt>
                        <dd>{{ asset.model }}</dd>
                    </template>
                    <template v-if="asset.serials.length">
                        <dt class="text-muted-foreground">{{ t('qr.serial') }}</dt>
                        <dd class="break-all font-mono">{{ asset.serials.join(', ') }}</dd>
                    </template>
                    <dt class="text-muted-foreground">{{ t('assets.status') }}</dt>
                    <dd>{{ t(`assets.statuses.${asset.status}`) }}</dd>
                </dl>
            </section>
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

            <!-- Report a problem: a button at the end, the form once it is pressed -->
            <button
                v-else-if="canReport && asset && !reporting"
                type="button"
                :class="[big, 'bg-gradient-to-r from-violet-600 to-indigo-500 text-white shadow-lg']"
                @click="startReport"
            >
                <Wrench class="size-5" />{{ t('qr.report_button') }}
            </button>
            <form
                v-else-if="canReport && asset"
                ref="formEl"
                class="scroll-mt-4 space-y-5 rounded-2xl border bg-white p-5 dark:bg-slate-950"
                @submit.prevent="submit"
            >
                <h2 class="text-lg font-semibold">{{ t('qr.report_button') }}</h2>
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

                <!-- Saved on this phone from the last report: shown, changed only when asked -->
                <div v-if="!contactOpen" class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm dark:bg-slate-900">
                    <div class="min-w-0">
                        <div class="text-muted-foreground">{{ t('qr.reporter') }}</div>
                        <div class="truncate font-semibold">{{ [form.name, form.phone || form.email].filter(Boolean).join(' · ') }}</div>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 text-sm font-medium text-violet-700 underline dark:text-violet-300"
                        @click="editingContact = true"
                    >
                        {{ t('qr.reporter_change') }}
                    </button>
                </div>
                <label v-if="contactOpen" class="block space-y-1.5">
                    <span class="font-semibold">{{ t('qr.name') }} <span class="text-red-600">*</span></span>
                    <input v-model="form.name" :class="input" maxlength="100" autocomplete="name" required />
                    <InputError :message="form.errors.name" />
                </label>
                <div v-if="contactOpen" class="space-y-1.5">
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
