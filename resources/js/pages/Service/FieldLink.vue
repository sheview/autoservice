<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SignaturePad from '@/components/SignaturePad.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { shrinkImage } from '@/lib/image';
import type { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Camera, Check, CircleX, FileSignature, Printer, Trash2, Wrench } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * A ticket's link, without an account, on a phone or an iPad. An outside technician ("work") sees
 * the job and sends back what was found and done, parts used, photos, and the customer's sign-off:
 * signed right here on the screen, or on the printed job sheet (then a photo of it). The customer
 * ("sign") only signs it off. Sending again replaces the report. The helpdesk closes the job.
 */
const props = defineProps<{
    page: {
        company: string | null;
        link: { mode: 'work' | 'sign'; holder_name: string; holder_company: string | null; usable: boolean; expires_at: string; submitted_at: string | null };
        ticket: {
            ticket_no: string;
            title: string;
            description: string | null;
            status: string;
            customer: string | null;
            contact_name: string | null;
            contact_phone: string | null;
            branch: string | null;
            device: { name: string | null; brand: string | null; model: string | null; serial: string | null; location: string | null };
            report: { cause: string | null; approver_name: string | null };
        };
        signature: { signer: string | null; signed_at: string | null } | null;
        presets: { symptom: string[]; solution: string[] } | null;
    } | null;
    limits: { photos: number; photo_kb: number };
}>();

const shared = usePage<SharedData>();
const errors = computed(() => shared.props.errors as Record<string, string>);
const work = computed(() => props.page?.link.mode === 'work');

const form = useForm({
    symptoms: [] as string[],
    solutions: [] as string[],
    parts: '',
    note: '',
    signer_name: props.page?.ticket.contact_name ?? '',
    signature: null as string | null,
    before: [] as File[],
    after: [] as File[],
    signed_sheet: null as File | null,
});

// How the customer signs off: on the screen here, or on paper (printed sheet, then a photo).
const how = ref<'screen' | 'paper'>('screen');

const toggle = (list: string[], label: string) => {
    const i = list.indexOf(label);
    if (i >= 0) list.splice(i, 1);
    else list.push(label);
};

const previews = ref<{ before: string[]; after: string[] }>({ before: [], after: [] });
const addPhotos = async (stage: 'before' | 'after', event: Event) => {
    const input = event.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        if (form[stage].length >= props.limits.photos) break;
        const small = await shrinkImage(file);
        form[stage].push(small);
        previews.value[stage].push(URL.createObjectURL(small));
    }
    input.value = '';
};
const removePhoto = (stage: 'before' | 'after', i: number) => {
    form[stage].splice(i, 1);
    previews.value[stage].splice(i, 1);
};
const sheetPreview = ref<string | null>(null);
const addSheet = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    form.signed_sheet = await shrinkImage(file, 2200);
    sheetPreview.value = URL.createObjectURL(form.signed_sheet);
};

const printUrl = computed(() => `${window.location.pathname}/print`);
const submit = () =>
    form
        .transform((data) => ({
            ...data,
            signature: how.value === 'screen' ? data.signature : null,
            signed_sheet: how.value === 'paper' ? data.signed_sheet : null,
        }))
        .post(window.location.pathname, { forceFormData: true, preserveScroll: true });

const chip = (on: boolean) => (on ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background');
const input = 'h-12 w-full rounded-xl border border-input bg-transparent px-3 text-base';
</script>

<template>
    <Head :title="page ? page.ticket.ticket_no : t('field_link.title')" />

    <div class="min-h-svh bg-gradient-to-b from-violet-50 to-white px-4 py-6 pb-28 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-3xl space-y-5">
            <div class="flex items-center gap-3">
                <div class="flex size-12 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 text-white shadow">
                    <component :is="work ? Wrench : FileSignature" class="size-6" />
                </div>
                <div>
                    <h1 class="text-xl font-semibold">{{ work ? t('field_link.title') : t('field_link.sign_title') }}</h1>
                    <p v-if="page?.company" class="text-sm text-violet-700 dark:text-violet-300">{{ page.company }}</p>
                </div>
            </div>

            <div v-if="!page" class="rounded-2xl border bg-background p-6 text-center text-muted-foreground">{{ t('field_link.not_found') }}</div>

            <template v-else>
                <p v-if="shared.props.flash.success" class="flex items-center gap-2 rounded-2xl bg-green-50 p-4 font-semibold text-green-800 dark:bg-green-950 dark:text-green-200">
                    <Check class="size-5" />{{ shared.props.flash.success }}
                </p>
                <p v-else-if="page.link.submitted_at" class="rounded-2xl bg-muted/60 p-3 text-sm">{{ t('field_link.sent_before', { at: dateTime(page.link.submitted_at) }) }}</p>

                <!-- The job -->
                <section class="space-y-2 rounded-2xl border bg-background p-5 text-sm">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="font-mono font-semibold">{{ page.ticket.ticket_no }}</span>
                        <span class="text-muted-foreground">{{ t('field_link.for', { name: page.link.holder_name }) }}</span>
                    </div>
                    <p class="text-base font-semibold">{{ page.ticket.title }}</p>
                    <p v-if="page.ticket.description" class="whitespace-pre-line">{{ page.ticket.description }}</p>
                    <dl class="grid gap-2 pt-2 sm:grid-cols-2">
                        <div v-if="page.ticket.customer">
                            <dt class="text-xs text-muted-foreground">{{ t('field_link.customer') }}</dt>
                            <dd>{{ page.ticket.customer }}<template v-if="page.ticket.branch"> · {{ page.ticket.branch }}</template></dd>
                        </div>
                        <div v-if="page.ticket.contact_name">
                            <dt class="text-xs text-muted-foreground">{{ t('field_link.contact') }}</dt>
                            <dd>
                                {{ page.ticket.contact_name }}
                                <a v-if="page.ticket.contact_phone" :href="`tel:${page.ticket.contact_phone}`" class="ml-1 text-primary">{{ page.ticket.contact_phone }}</a>
                            </dd>
                        </div>
                        <div v-if="page.ticket.device.name" class="sm:col-span-2">
                            <dt class="text-xs text-muted-foreground">{{ t('field_link.device') }}</dt>
                            <dd>
                                {{ [page.ticket.device.name, page.ticket.device.brand, page.ticket.device.model].filter(Boolean).join(' · ') }}
                                <span v-if="page.ticket.device.serial" class="font-mono text-xs"> SN {{ page.ticket.device.serial }}</span>
                                <span v-if="page.ticket.device.location" class="block text-xs text-muted-foreground">{{ page.ticket.device.location }}</span>
                            </dd>
                        </div>
                        <div v-if="!work && page.ticket.report.cause" class="sm:col-span-2">
                            <dt class="text-xs text-muted-foreground">{{ t('field_link.work_done') }}</dt>
                            <dd class="whitespace-pre-line">{{ page.ticket.report.cause }}</dd>
                        </div>
                    </dl>
                </section>

                <p v-if="!page.link.usable" class="flex items-center gap-2 rounded-2xl bg-red-50 p-4 text-red-800 dark:bg-red-950 dark:text-red-200">
                    <CircleX class="size-5" />{{ t('field_link.unusable') }}
                </p>

                <form v-else class="space-y-5" @submit.prevent="submit">
                    <InputError :message="errors.link || errors.symptoms" />

                    <!-- What was wrong and what was done (outside technician) -->
                    <template v-if="work && page.presets">
                        <section v-for="kind in ['symptom', 'solution'] as const" :key="kind" class="space-y-2 rounded-2xl border bg-background p-5">
                            <h2 class="font-semibold">{{ kind === 'symptom' ? t('close.symptoms') : t('close.solutions') }}</h2>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="label in page.presets[kind]"
                                    :key="label"
                                    type="button"
                                    class="min-h-11 rounded-full border px-4 text-sm"
                                    :class="chip((kind === 'symptom' ? form.symptoms : form.solutions).includes(label))"
                                    @click="toggle(kind === 'symptom' ? form.symptoms : form.solutions, label)"
                                >
                                    {{ label }}
                                </button>
                            </div>
                        </section>
                        <section class="space-y-3 rounded-2xl border bg-background p-5">
                            <label class="block space-y-1.5 text-sm">
                                <span class="font-semibold">{{ t('field_link.parts') }}</span>
                                <textarea v-model="form.parts" rows="2" maxlength="1000" :placeholder="t('field_link.parts_placeholder')" class="w-full rounded-xl border border-input bg-transparent px-3 py-2 text-base" />
                            </label>
                            <label class="block space-y-1.5 text-sm">
                                <span class="font-semibold">{{ t('field_link.note') }}</span>
                                <textarea v-model="form.note" rows="3" maxlength="2000" class="w-full rounded-xl border border-input bg-transparent px-3 py-2 text-base" />
                            </label>
                        </section>
                        <section class="space-y-3 rounded-2xl border bg-background p-5">
                            <h2 class="font-semibold">{{ t('field_link.photos') }}</h2>
                            <div v-for="stage in ['before', 'after'] as const" :key="stage" class="space-y-2">
                                <div class="text-sm">{{ t(`close.${stage}`) }}</div>
                                <div class="flex flex-wrap gap-2">
                                    <div v-for="(src, i) in previews[stage]" :key="src" class="relative">
                                        <img :src="src" class="size-20 rounded-lg object-cover" alt="" />
                                        <button type="button" class="absolute -right-2 -top-2 rounded-full bg-background p-1 shadow" @click="removePhoto(stage, i)">
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>
                                    <label v-if="form[stage].length < limits.photos" class="flex size-20 cursor-pointer items-center justify-center rounded-lg border-2 border-dashed">
                                        <Camera class="size-6 text-muted-foreground" />
                                        <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="addPhotos(stage, $event)" />
                                    </label>
                                </div>
                            </div>
                        </section>
                    </template>

                    <!-- The customer's sign-off -->
                    <section class="space-y-3 rounded-2xl border bg-background p-5">
                        <h2 class="font-semibold">{{ t('field_link.sign_off') }}</h2>
                        <div v-if="work" class="flex flex-wrap gap-2">
                            <button type="button" class="min-h-11 rounded-full border px-4 text-sm" :class="chip(how === 'screen')" @click="how = 'screen'">
                                {{ t('field_link.sign_screen') }}
                            </button>
                            <button type="button" class="min-h-11 rounded-full border px-4 text-sm" :class="chip(how === 'paper')" @click="how = 'paper'">
                                {{ t('field_link.sign_paper') }}
                            </button>
                        </div>
                        <p v-if="page.signature" class="text-xs text-muted-foreground">
                            {{ t('field_link.signed_before', { name: page.signature.signer ?? '-', at: page.signature.signed_at ? dateTime(page.signature.signed_at) : '-' }) }}
                        </p>
                        <label class="block space-y-1.5 text-sm">
                            <span class="font-semibold">{{ t('field_link.signer') }}</span>
                            <input v-model="form.signer_name" maxlength="255" :class="input" />
                        </label>
                        <InputError :message="errors.signer_name" />
                        <template v-if="how === 'screen' || !work">
                            <p class="text-sm text-muted-foreground">{{ t('field_link.sign_here') }}</p>
                            <SignaturePad v-model="form.signature" />
                            <InputError :message="errors.signature" />
                        </template>
                        <template v-else>
                            <a :href="printUrl" target="_blank" class="flex min-h-12 items-center justify-center gap-2 rounded-xl border text-sm font-medium">
                                <Printer class="size-5" />{{ t('field_link.print') }}
                            </a>
                            <p class="text-xs text-muted-foreground">{{ t('field_link.paper_hint') }}</p>
                            <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed text-sm">
                                <Camera class="size-5" />{{ t('field_link.sheet_photo') }}
                                <input type="file" accept="image/*" capture="environment" class="hidden" @change="addSheet" />
                            </label>
                            <img v-if="sheetPreview" :src="sheetPreview" class="max-h-64 rounded-lg border" alt="" />
                            <InputError :message="errors.signed_sheet" />
                        </template>
                    </section>

                    <div class="fixed inset-x-0 bottom-0 border-t bg-background/95 p-3 backdrop-blur">
                        <div class="mx-auto max-w-3xl">
                            <button
                                type="submit"
                                :disabled="form.processing || (!work && !form.signature)"
                                class="flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl bg-primary text-base font-semibold text-primary-foreground shadow-lg disabled:opacity-50"
                            >
                                <Check class="size-6" />{{ work ? t('field_link.send') : t('field_link.send_sign') }}
                            </button>
                        </div>
                    </div>
                </form>
            </template>
        </div>
    </div>
</template>
